-- Endereço completo + coordenadas para geolocalização (mapa no dashboard).

ALTER TABLE chamados
    ADD COLUMN endereco_completo TEXT NULL AFTER descricao,
    ADD COLUMN latitude DECIMAL(10,7) NULL AFTER endereco_completo,
    ADD COLUMN longitude DECIMAL(11,7) NULL AFTER latitude;

UPDATE chamados SET
    endereco_completo = 'Av. Paulista, 1578 - Bela Vista, São Paulo - SP, CEP 01310-200',
    latitude = -23.5614140,
    longitude = -46.6558810
WHERE id = 1042 LIMIT 1;

UPDATE chamados SET
    endereco_completo = 'Rua Oscar Freire, 379 - Jardins, São Paulo - SP',
    latitude = -23.5629360,
    longitude = -46.6682420
WHERE id = 1041 LIMIT 1;

UPDATE chamados SET
    endereco_completo = 'Av. Brigadeiro Faria Lima, 3477 - Itaim Bibi, São Paulo - SP',
    latitude = -23.5874140,
    longitude = -46.6924140
WHERE id = 1038 LIMIT 1;
