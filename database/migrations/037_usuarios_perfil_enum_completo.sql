-- Garante que `usuarios.perfil` aceita os quatro perfis usados pelo CRM (incl. gestor).
-- Corrige bases antigas que ficaram só com ENUM('admin','cliente','operador') após migrações parciais.
ALTER TABLE usuarios
  MODIFY COLUMN perfil ENUM('admin','cliente','operador','gestor') NOT NULL;
