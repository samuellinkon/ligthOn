-- Remove o módulo «contas» da matriz SaaS (sidebar + super_modulos).

DELETE FROM perfil_modulos WHERE modulo_key = 'contas';
DELETE FROM saas_modulos WHERE modulo_key = 'contas';
