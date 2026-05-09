-- Remove a camada antiga de perfis de módulos.
-- As permissões passam a ser controladas apenas por role em saas_modulos:
-- admin, gestor, cliente e operador.

UPDATE usuarios SET modulo_perfil = NULL;

DROP TABLE IF EXISTS perfil_modulos;
