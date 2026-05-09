<?php
$id = (int) ($_GET['id'] ?? 0);
header('Location: ponto_iluminacao_novo.php' . ($id > 0 ? '?id=' . $id : ''));
exit;
