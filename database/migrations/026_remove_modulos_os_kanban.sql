-- Remove módulos OS e Kanban da instância (telas e sidebar); tabelas `os` e `kanban_cards` permanecem para dados legados.
DELETE FROM perfil_modulos WHERE modulo_key IN ('os', 'kanban');
DELETE FROM saas_modulos WHERE modulo_key IN ('os', 'kanban');
