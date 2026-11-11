<?php
declare(strict_types=1);

namespace Att;

/**
 * Checking and re-making an uploaded picture.
 *
 * Two jobs, and the second is the one that matters.
 *
 * 1. INSPECT. What a file IS is decided by reading it, never by its name or
 *    by what the phone said it was (blueprint §6, "validate MIME via file
 *    inspection"). Two independent readers must agree: libmagic on the bytes
 *    and the image decoder on the header.
 *
 * 2. RE-MAKE. Nothing a phone sends is ever stored. The picture is decoded
 *    to pixels and a NEW JPEG is written from those pixels. That is the
 *    malware policy: a file that is a valid image and also something else —
 *    a script after the end marker, a payload in a comment block — does not
 *    survive, because only pixels cross. It also drops EXIF, which on a
 *    phone photo means the GPS position, the handset model and the time.
 *    And it makes a 6 MB camera original into about 30 KB: a fixed-size
 *    WebP that a gate phone loads over mobile data in a moment. No original
 *    is kept anywhere.
 */
final class Images
{
    public const ALLOWED     = ['image/jpeg', 'image/png', 'image/webp'];
    public const MAX_PIXELS  = 40000000;   // refuse to decode beyond 40 megapixels
    public const MAX_SOURCE  = 10485760;   // 10 MB in — a file straight off a phone camera. Nothing is KEPT at that size.

    public static function available(): bool
    {
        return function_exists('imagecreatefromstring') && function_exists('imagejpeg') && function_exists('finfo_open');
    }

    /** @return array{mime:string,width:int,height:int}|null null = not an image we accept */
    public static function inspect(string $bytes): ?array
    {
        if ($bytes === '' || !function_exists('finfo_open')) {
            return null;
        }
        $f = finfo_open(FILEINFO_MIME_TYPE);
        $magic = $f ? (string) finfo_buffer($f, $bytes) : '';
        $info  = @getimagesizefromstring($bytes);
        if ($info === false || !in_array($magic, self::ALLOWED, true)) {
            return null;
        }
        $decoderSays = (string) ($info['mime'] ?? '');
        if ($decoderSays !== $magic) {
            return null;          // the two readers disagree about what this is
        }
        $w = (int) $info[0];
        $h = (int) $info[1];
        if ($w < 1 || $h < 1) {
            return null;
        }
        return ['mime' => $magic, 'width' => $w, 'height' => $h];
    }

    /** Reference photo: every one the same shape and size, a 4:5 portrait. */
    public const REFERENCE = ['width' => 480, 'height' => 600, 'crop' => true, 'min_width' => 160, 'min_height' => 200];
    /** Flagged still: the whole frame, never cropped — it is evidence — fitted inside 1280. */
    public const FLAG      = ['width' => 1280, 'height' => 1280, 'crop' => false, 'min_width' => 120, 'min_height' => 120];

    /** WebP where this server can write it (a third the size of JPEG for a face); JPEG where it cannot. */
    public static function outputFormat(): array
    {
        return function_exists('imagewebp')
            ? ['mime' => 'image/webp', 'ext' => 'webp']
            : ['mime' => 'image/jpeg', 'ext' => 'jpg'];
    }

    /** What a stored picture is, read from its first bytes. */
    public static function mimeOf(string $bytes): string
    {
        return (strncmp($bytes, 'RIFF', 4) === 0 && substr($bytes, 8, 4) === 'WEBP') ? 'image/webp' : 'image/jpeg';
    }

    /**
     * Decode, turn upright, bring to the size the system keeps, and write a
     * fresh file.
     *
     * NOTHING IS KEPT AT THE SIZE IT ARRIVED. A reference photo ends up
     * exactly 480 × 600 whatever was uploaded: cropped to that shape, then
     * scaled. The crop keeps the middle left-to-right and favours the upper
     * part top-to-bottom, because in a picture of a person the face is in
     * the upper half; it is a fixed rule, not face recognition, and the
     * Setup screen shows the result so a bad crop is seen at once. A flagged
     * still is scaled to fit and never cropped.
     *
     * @param array{width:int,height:int,crop:bool,min_width:int,min_height:int} $spec
     * @return array{ok:true,bytes:string,width:int,height:int,mime:string,ext:string}|array{ok:false,reason_code:string,error:string}
     */
    public static function normalize(string $bytes, array $spec, int $maxSourceBytes = self::MAX_SOURCE): array
    {
        if (!self::available()) {
            return self::no('IMAGE_TOOLS_MISSING', 'This server cannot process pictures (the PHP GD extension is missing).');
        }
        if (strlen($bytes) > $maxSourceBytes) {
            return self::no('IMAGE_TOO_LARGE', 'That picture is larger than ' . self::mb($maxSourceBytes) . '.');
        }
        $info = self::inspect($bytes);
        if ($info === null) {
            return self::no('NOT_AN_IMAGE', 'That file is not a JPG, PNG or WebP picture.');
        }
        if ($info['width'] * $info['height'] > self::MAX_PIXELS) {
            return self::no('IMAGE_TOO_LARGE', 'That picture has too many pixels to process.');
        }

        @ini_set('memory_limit', '512M');
        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            return self::no('NOT_AN_IMAGE', 'That picture could not be read. Try saving it again as a JPG.');
        }

        // A phone held upright stores the picture sideways plus a note saying
        // "turn it". The note is about to be thrown away, so turn it now.
        if ($info['mime'] === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($bytes));
            $turn = [3 => 180, 6 => -90, 8 => 90][(int) ($exif['Orientation'] ?? 0)] ?? 0;
            if ($turn !== 0) {
                $rot = imagerotate($src, $turn, 0);
                if ($rot !== false) {
                    imagedestroy($src);
                    $src = $rot;
                }
            }
        }

        $w = imagesx($src);
        $h = imagesy($src);
        if ($w < $spec['min_width'] || $h < $spec['min_height']) {
            imagedestroy($src);
            return self::no('IMAGE_TOO_SMALL', "That picture is only $w × $h. It needs to be at least {$spec['min_width']} × {$spec['min_height']} to be recognisable.");
        }

        $sx = 0; $sy = 0; $sw = $w; $sh = $h;
        if ($spec['crop']) {
            $nw = $spec['width'];
            $nh = $spec['height'];
            $want = $nw / $nh;
            if ($w / $h > $want) {                 // too wide: take the middle
                $sw = (int) round($h * $want);
                $sx = (int) floor(($w - $sw) / 2);
            } else {                               // too tall: favour the top
                $sh = (int) round($w / $want);
                $sy = (int) floor(($h - $sh) * 0.2);
            }
        } else {
            $scale = min(1.0, $spec['width'] / $w, $spec['height'] / $h);
            $nw = max(1, (int) round($w * $scale));
            $nh = max(1, (int) round($h * $scale));
        }

        $dst = imagecreatetruecolor($nw, $nh);
        // Neither output keeps transparency; a see-through PNG goes onto white, not black.
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $nw, $nh, $sw, $sh);
        imagedestroy($src);

        $fmt = self::outputFormat();
        ob_start();
        $ok  = $fmt['ext'] === 'webp' ? imagewebp($dst, null, 80) : imagejpeg($dst, null, 82);
        $out = (string) ob_get_clean();
        imagedestroy($dst);

        if (!$ok || $out === '') {
            return self::no('IMAGE_FAILED', 'The picture could not be prepared for storing.');
        }
        return ['ok' => true, 'bytes' => $out, 'width' => $nw, 'height' => $nh, 'mime' => $fmt['mime'], 'ext' => $fmt['ext']];
    }

    private static function mb(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / 1048576, 1), '0'), '.') . ' MB';
    }

    private static function no(string $code, string $message): array
    {
        return ['ok' => false, 'reason_code' => $code, 'error' => $message];
    }
}
