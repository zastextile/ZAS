<?php
/* This file used to clear the server's code cache for ANYBODY who opened its
   address — no login. It now sends people to the proper tool, which asks for
   an admin login first (opcache_clear.php). Safe to delete from the server. */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_admin();
redirect('opcache_clear.php');
