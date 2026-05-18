<?php
require_once __DIR__ . '/../includes/auth.php';

require_super_admin();

header('Location: configuracoes.php?tab=modulos', true, 302);
exit;
