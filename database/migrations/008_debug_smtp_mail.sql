-- Modo debug (formulários) + remetente e SMTP (ex.: Hostinger)
INSERT IGNORE INTO app_config (chave, valor) VALUES
('debug_mode', '0'),
('mail_from', ''),
('mail_from_name', ''),
('smtp_enabled', '0'),
('smtp_host', ''),
('smtp_port', '587'),
('smtp_encryption', 'tls'),
('smtp_user', ''),
('smtp_password', '');
