<?php
/**
 * API JSON — pontos de iluminação por área visível do mapa (viewport / bounding box).
 */
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../includes/pontos_mapa_api_handler.php';
