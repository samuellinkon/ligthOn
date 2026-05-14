<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/audit_log.php';
$uOut = function_exists('current_user') ? current_user() : null;
if ($uOut) {
    $cidLog = 0;
    if (!empty($uOut['empresa_id'])) {
        $cidLog = (int) $uOut['empresa_id'];
    } elseif (!empty($uOut['cliente_id'])) {
        $cidLog = (int) $uOut['cliente_id'];
    }
    audit_log_registar('auth.logout', 'sessao', null, $cidLog > 0 ? $cidLog : null, [], null);
}
mock_logout();
header('Location: login.php');
exit;
