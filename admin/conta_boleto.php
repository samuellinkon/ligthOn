<?php
require_once __DIR__ . '/../includes/auth.php';
require_auth_gestao();
header('Location: index.php', true, 302);
exit;
