# Assets para seeds (SQL / scripts PHP)

## Foto de poste — `poste_iluminacao.jpg`

Coloque **uma** foto de poste de iluminação pública nesta pasta com o nome exato:

```
database/seed_assets/poste_iluminacao.jpg
```

Formatos aceitos na origem: JPG, PNG ou WEBP (o script converte para JPEG padronizado).

### Uso

Depois de cadastrar os 10 pontos `IPOJUCA-PREF-*`:

```bash
php scripts/seed_pontos_iluminacao_fotos_ipojuca.php
```

Substituir fotos já vinculadas:

```bash
php scripts/seed_pontos_iluminacao_fotos_ipojuca.php --force
```

O script grava em cada ponto:

- Arquivo: `uploads/pontos_iluminacao/{ponto_id}/principal_1200x900.jpg`
- Resolução: **1200 × 900 px** (proporção 4:3)
- Registro em `ponto_iluminacao_imagens` (foto principal)

Alternativa só SQL (após copiar os JPEGs manualmente): `database/seed_pontos_iluminacao_fotos_ipojuca.sql`

**Não versionar** fotos grandes no Git se não for necessário; basta este README e o arquivo local.
