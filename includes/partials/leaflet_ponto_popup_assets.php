<?php
/** JS do popup de pontos (Leaflet). CSS em head.php ou na página. Requer $basePath. */
if (!isset($basePath)) {
    $basePath = '../';
}
$crmMapPopupJs = dirname(__DIR__, 2) . '/assets/js/ponto-iluminacao-popup.js';
?>
<script src="<?= htmlspecialchars($basePath) ?>assets/js/ponto-iluminacao-popup.js?v=<?= (int) @filemtime($crmMapPopupJs) ?>"></script>
