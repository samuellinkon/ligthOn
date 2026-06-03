<?php
/**
 * API JSON — detalhe de poste para popup do mapa (carregamento sob demanda).
 */
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../includes/ponto_mapa_detalhe_api_handler.php';
