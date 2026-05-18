<?php
/**
 * Compatibilidade: PIX global foi removido — redireciona para Configurações.
 */
require_once __DIR__ . '/../includes/auth.php';
require_auth('admin');
require_once __DIR__ . '/../includes/modules.php';
require_modulo_admin('configuracoes');

header('Location: configuracoes.php?tab=geral', true, 302);
exit;
