<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/customer.php';
customer_clear_remember_cookie();
session_destroy();
redirect('customer_login.php');
