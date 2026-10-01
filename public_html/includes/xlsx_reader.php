<?php
/*
  Lightweight XLSX reader for preview/import assistance.
  Reads cell text from worksheets. Does not evaluate formulas.
*/
function xlsx_read_sheets(string $file): array {
    $zip = new ZipArchive();
    if ($zip->open($file) !== true) {
        throw new Exception('Cannot open XLSX file.');
    }

    $shared = [];
    $ss = $zip->getFromName('xl/sharedStrings.xml');
    if ($ss !== false) {
        $xml = simplexml_load_string($ss);
        foreach ($xml->si as $si) {
            $text = '';
            if (isset($si->t)) $text .= (string)$si->t;
            if (isset($si->r)) foreach ($si->r as $r) $text .= (string)$r->t;
            $shared[] = $text;
        }
    }

    $workbook = simplexml_load_string($zip->getFromName('xl/workbook.xml'));
    $rels = simplexml_load_string($zip->getFromName('xl/_rels/workbook.xml.rels'));
    $relMap = [];
    foreach ($rels->Relationship as $r) {
        $relMap[(string)$r['Id']] = (string)$r['Target'];
    }

    $sheets = [];
    foreach ($workbook->sheets->sheet as $sheet) {
        $name = (string)$sheet['name'];
        $rid = (string)$sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
        $target = $relMap[$rid] ?? '';
        $path = 'xl/' . ltrim($target, '/');
        $xmlRaw = $zip->getFromName($path);
        if ($xmlRaw === false) continue;
        $xml = simplexml_load_string($xmlRaw);
        $rows = [];
        foreach ($xml->sheetData->row as $row) {
            $rIndex = (int)$row['r'];
            $rowData = [];
            foreach ($row->c as $c) {
                $ref = (string)$c['r'];
                preg_match('/([A-Z]+)/', $ref, $m);
                $col = letters_to_number($m[1] ?? 'A') - 1;
                $type = (string)$c['t'];
                $v = isset($c->v) ? (string)$c->v : '';
                if ($type === 's') $v = $shared[(int)$v] ?? '';
                if ($type === 'inlineStr' && isset($c->is->t)) $v = (string)$c->is->t;
                $rowData[$col] = $v;
            }
            if ($rowData) {
                ksort($rowData);
                $rows[$rIndex] = $rowData;
            }
        }
        $sheets[$name] = $rows;
    }

    $zip->close();
    return $sheets;
}

function letters_to_number(string $letters): int {
    $num = 0;
    $letters = strtoupper($letters);
    for ($i=0; $i<strlen($letters); $i++) {
        $num = $num * 26 + (ord($letters[$i]) - 64);
    }
    return $num;
}
