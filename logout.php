<?php
require_once __DIR__ . '/includes/auth.php';
mock_logout();
header('Location: login.php');
exit;
