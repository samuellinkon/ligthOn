-- Remove mass import Ipojuca; manter apenas pontos de demonstração (ver seed_pontos_iluminacao_demo_30.sql)
DELETE FROM pontos_iluminacao
WHERE observacoes LIKE '%Importado da planilha Cadastro Ipojuca%'
   OR observacoes LIKE '%Ipojuca Janeiro 2026%';
