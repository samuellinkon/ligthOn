-- =============================================================================
-- Fotos principais nos 10 pontos IPOJUCA-PREF (Prefeitura Ipojuca)
-- =============================================================================
-- PRÉ-REQUISITO: o arquivo principal_1200x900.jpg deve existir em disco em cada
-- pasta uploads/pontos_iluminacao/{ponto_id}/ — use o script PHP (recomendado):
--
--   php scripts/seed_pontos_iluminacao_fotos_ipojuca.php
--
-- Este SQL só registra metadados no banco. Não redimensiona nem copia imagens.
-- Tamanho estimado (~95 KB) — após rodar o script PHP, o tamanho real é gravado.
-- =============================================================================

SET NAMES utf8mb4;

SET @nome_arquivo := 'principal_1200x900.jpg';
SET @tamanho_estimado := 95000;

DELETE pi FROM ponto_iluminacao_imagens pi
INNER JOIN pontos_iluminacao p ON p.id = pi.ponto_iluminacao_id
WHERE p.codigo_poste LIKE 'IPOJUCA-PREF-%'
  AND pi.nome_arquivo = @nome_arquivo;

INSERT INTO ponto_iluminacao_imagens
    (ponto_iluminacao_id, nome_original, nome_arquivo, mime, tamanho, principal, ordem)
SELECT
    p.id,
    'Foto do poste (seed)',
    @nome_arquivo,
    'image/jpeg',
    @tamanho_estimado,
    1,
    0
FROM pontos_iluminacao p
WHERE p.codigo_poste LIKE 'IPOJUCA-PREF-%'
  AND NOT EXISTS (
      SELECT 1 FROM ponto_iluminacao_imagens x
      WHERE x.ponto_iluminacao_id = p.id AND x.principal = 1
  );

-- Conferência
SELECT p.codigo_poste, pi.nome_arquivo, pi.tamanho, pi.principal
FROM pontos_iluminacao p
LEFT JOIN ponto_iluminacao_imagens pi
    ON pi.ponto_iluminacao_id = p.id AND pi.principal = 1
WHERE p.codigo_poste LIKE 'IPOJUCA-PREF-%'
ORDER BY p.codigo_poste;
