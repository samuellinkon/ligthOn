<?php
/**
 * API JSON — geocode Nominatim (proxy com cache; portal operador).
 */
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=UTF-8');

require_auth('operador', '../');
require_once __DIR__ . '/../includes/geocode_nominatim_api_handler.php';
