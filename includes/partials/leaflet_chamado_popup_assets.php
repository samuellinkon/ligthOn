<?php
/** JS do popup de chamados (Leaflet). CSS em head.php. Requer $basePath. */
if (!isset($basePath)) {
    $basePath = '../';
}
$crmChamadoPopupJs = dirname(__DIR__, 2) . '/assets/js/chamado-popup.js';
?>
<script src="<?= htmlspecialchars($basePath) ?>assets/js/chamado-popup.js?v=<?= (int) @filemtime($crmChamadoPopupJs) ?>"></script>
