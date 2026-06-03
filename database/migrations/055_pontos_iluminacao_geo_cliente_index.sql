-- Índice composto para consultas geográficas por cliente/escopo.
-- Melhora performance de repo_pontos_iluminacao_mapa_bounds.

ALTER TABLE pontos_iluminacao
    ADD INDEX idx_ponto_cliente_geo (cliente_id, latitude, longitude);
