-- Perfil operador (N por empresa), serviços/produtos por cliente, campos no chamado, PWA-ready (app).
--
-- NÃO reduzir ENUM de `usuarios.perfil` aqui: ao reexecutar migrate.php a 016 corre antes da 019 e
-- apagava o valor `gestor` dos dados (erro 1265 "Data truncated for column 'perfil' at row N").
-- O ENUM completo fica em 019_saas_quatro_grupos_gestor.sql e 037_usuarios_perfil_enum_completo.sql.

CREATE TABLE IF NOT EXISTS cliente_servicos (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id  INT UNSIGNED NOT NULL,
    nome        VARCHAR(160) NOT NULL,
    ativo       TINYINT(1) NOT NULL DEFAULT 1,
    ordem       INT NOT NULL DEFAULT 0,
    criado_em   DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
    INDEX idx_cs_cliente (cliente_id, ativo, ordem)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE chamados
    ADD COLUMN servico_id INT UNSIGNED NULL AFTER longitude,
    ADD COLUMN finalizado_operador_em DATETIME NULL AFTER servico_id,
    ADD COLUMN finalizado_operador_user_id INT UNSIGNED NULL AFTER finalizado_operador_em,
    ADD CONSTRAINT fk_chamados_servico FOREIGN KEY (servico_id) REFERENCES cliente_servicos(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_chamados_op_user FOREIGN KEY (finalizado_operador_user_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    ADD INDEX idx_chamados_servico (servico_id);

ALTER TABLE chamado_respostas MODIFY COLUMN tipo ENUM('admin','cliente','operador') NOT NULL;

ALTER TABLE chamado_anexos MODIFY COLUMN enviado_tipo ENUM('admin','cliente','operador') NOT NULL DEFAULT 'admin';

-- NÃO encolher `saas_modulos.grupo` / `perfil_modulos.grupo` aqui: bases já migradas pela 019 têm
-- super_admin e gestor; reexecutar este MODIFY corrompe dados. A 019 define o ENUM final.
INSERT IGNORE INTO saas_modulos (grupo, modulo_key, label, habilitado, ordem) VALUES
('operador', 'chamados', 'Chamados', 1, 10);

INSERT IGNORE INTO perfil_modulos (perfil_key, grupo, modulo_key, habilitado)
SELECT p.perfil_key, 'operador', 'chamados', 1
FROM (SELECT DISTINCT perfil_key FROM perfil_modulos) AS p;
