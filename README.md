# LightOn — Gestão em Iluminação (PHP + HTML/CSS/JS)

Projeto do CRM em **PHP puro + HTML/CSS/JS vanilla** com **banco MySQL/MariaDB**
e fallback automático para dados mock quando o banco está indisponível.

## Como rodar localmente

Você precisa de PHP 8.1+.

### Servidor embutido do PHP (mais simples)

Abra um terminal na pasta `crm/` e rode:

```bash
php -S localhost:8000
```

Depois acesse: http://localhost:8000

A `index.php` redireciona para `login.php`.

## Acessos mockados

| Perfil        | E-mail             | Senha        |
| ------------- | ------------------ | ------------ |
| Administrador | `admin@crm.com`    | `admin123`   |
| Cliente       | `cliente@crm.com`  | `cliente123` |

Na tela de login há botões **"Preencher"** que já jogam as credenciais nos
campos. A autenticação é simulada via `session_start()` (sem banco).
O admin é redirecionado para `admin/index.php` e o cliente para
`cliente/index.php`. Cada área valida o perfil — se o cliente tentar abrir
uma rota `/admin/*`, é redirecionado de volta ao login.

### Ou via XAMPP / WAMP

Copie a pasta `crm/` para `htdocs/` e acesse `http://localhost/crm/`.

## Banco de dados

O projeto usa **MySQL / MariaDB** (padrão do XAMPP). Se o banco estiver offline,
o sistema continua funcionando em modo **mock** automaticamente — útil em dev.

### Primeira instalação

1. Ligue o **MySQL** no XAMPP Control Panel.
2. Se suas credenciais forem diferentes do padrão (`root` sem senha), edite
   `includes/config.php`.
3. Acesse `http://localhost/crm/install.php` e clique em **Instalar agora**.
   O instalador:
   - cria o banco `crm_control`;
   - executa `database/schema.sql` (todas as tabelas);
   - executa `database/seed.sql` (dados iniciais);
   - cria os dois usuários padrão com `password_hash`.
4. Apague `install.php` depois de instalar.

### Arquitetura de dados

- `includes/config.php` — credenciais do MySQL.
- `includes/db.php` — conexão PDO (`db()` retorna `null` se indisponível).
- `includes/repository.php` — funções `repo_clientes()`, `repo_chamados()`, etc.
  que retornam arrays no mesmo formato do mock.
- `includes/mock.php` — se o banco estiver ativo, sobrescreve os arrays
  `$MOCK_*` com dados reais; senão mantém os arrays do próprio arquivo.
- `database/schema.sql` e `database/seed.sql` — estrutura e dados iniciais.

## Estrutura

```
crm/
├── assets/
│   ├── css/   (style, sidebar, tables, forms, dashboard, kanban, responsive)
│   ├── js/    (main, sidebar, kanban)
│   └── img/   (logo.svg, avatar-placeholder.svg)
├── database/  (schema.sql, seed.sql)
├── includes/  (config, db, repository, auth, mock, head, topbar, footer, sidebars)
├── admin/     (dashboard, clientes, chamados, kanban, contas, suporte, OS…)
├── cliente/   (dashboard, chamados, contas, suporte, OS…)
├── install.php (instalador web do banco)
├── index.php  (redireciona para login)
├── login.php
└── logout.php
```

## Navegação

- `/` → `login.php`
- Admin: `admin/index.php` (dashboard), `clientes.php`, `cliente_novo.php`,
  `chamados.php`, `chamado_novo.php`, `chamado_detalhe.php?id=1042`,
  `kanban.php` (drag & drop visual), `contas.php`, `conta_nova.php`, `suporte.php`
- Cliente: `cliente/index.php`, `chamados.php`, `chamado_novo.php`,
  `chamado_detalhe.php?id=1042`, `contas.php`, `suporte.php`

## Observação

Com o banco instalado, todas as telas leem dados reais do MySQL. Sem banco,
a aplicação continua funcional com os arrays mock de `includes/mock.php`.
As páginas não precisam saber a diferença — a camada de dados é transparente.

## Deploy na Hostinger (produção)

O site precisa das **três pastas de painel** na raiz pública (`public_html` ou equivalente):

| Pasta      | Quem usa        | Exemplo de URL                          |
| ---------- | --------------- | --------------------------------------- |
| `admin/`   | Admin / gestor  | `https://seu-dominio/admin/index.php`   |
| `operador/`| Operador campo  | `https://seu-dominio/operador/`         |
| `cliente/` | Portal prefeitura | `https://seu-dominio/cliente/index.php` |

Se o login do **cliente** falhar com «página não existe» ou 404 genérico da Hostinger,
quase sempre a pasta **`cliente/` não foi enviada** ao servidor (admin e operador
já estão lá, mas `cliente/` ficou de fora no FTP/Git).

**Correção:** envie a pasta `cliente/` completa (todos os `.php` do repositório) para
a mesma raiz onde estão `login.php`, `admin/` e `operador/`. Confirme no gestor de
ficheiros que existe `cliente/index.php`.

Também envie/atualize: `.htaccess`, `includes/`, `assets/`, `database/migrations/`
(aplicar SQL no phpMyAdmin quando houver migrações novas).
