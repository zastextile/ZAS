<?php
require_once __DIR__ . '/includes/bootstrap.php';
session_destroy();
redirect('login.php');
