<?php
/**
 * Footer / fechamento. Espera: $basePath.
 */
if (!isset($basePath)) $basePath = '../';
?>
  </main>
</div>

<script src="<?= $basePath ?>assets/js/confirm-modal.js"></script>
<script src="<?= $basePath ?>assets/js/sidebar.js"></script>
<script src="<?= $basePath ?>assets/js/main.js"></script>
<script src="<?= $basePath ?>assets/js/crm-tabela-sort.js?v=<?= (int) @filemtime(dirname(__DIR__) . '/assets/js/crm-tabela-sort.js') ?>"></script>
<script src="<?= $basePath ?>assets/js/crm-export-loading.js?v=<?= (int) @filemtime(dirname(__DIR__) . '/assets/js/crm-export-loading.js') ?>"></script>
<script src="<?= $basePath ?>assets/js/crm-custom-select.js?v=<?= (int) @filemtime(dirname(__DIR__) . '/assets/js/crm-custom-select.js') ?>"></script>
<script src="<?= $basePath ?>assets/js/crm-custom-file.js?v=<?= (int) @filemtime(dirname(__DIR__) . '/assets/js/crm-custom-file.js') ?>"></script>
<script src="<?= $basePath ?>assets/js/crm-input-masks.js?v=<?= (int) @filemtime(dirname(__DIR__) . '/assets/js/crm-input-masks.js') ?>"></script>
<script src="<?= $basePath ?>assets/js/crm-viacep.js?v=<?= (int) @filemtime(dirname(__DIR__) . '/assets/js/crm-viacep.js') ?>"></script>
<?php if (!empty($loadComposer)): ?>
<script src="<?= $basePath ?>assets/js/composer.js"></script>
<?php endif; ?>
<?php if (function_exists('app_debug_mode') && app_debug_mode()): ?>
<script src="<?= $basePath ?>assets/js/form-fill-dev.js"></script>
<?php endif; ?>
<?php if (!empty($operadorPwa)): ?>
<script>
(function () {
  if (!('serviceWorker' in navigator)) return;
  var src = <?= json_encode(($basePath ?? '../') . 'operador/sw.js') ?>;
  navigator.serviceWorker.register(src).catch(function () {});
})();
</script>
<?php endif; ?>
</body>
</html>
