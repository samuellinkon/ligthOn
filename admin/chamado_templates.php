<?php
/**
 * Compatibilidade: modelos de resposta foram removidos — redireciona ao painel de configurações.
 */
require_once __DIR__ . '/../includes/auth.php';
require_auth_gestao();
require_once __DIR__ . '/../includes/modules.php';
require_modulo_admin('chamados');

header('Location: configuracoes.php?tab=geral', true, 302);
exit;
