# Google Maps no CRM (chamados e mapas simples)

## Configuração

1. Crie um projeto no [Google Cloud Console](https://console.cloud.google.com/).
2. Ative a faturamento no projeto.
3. Ative as APIs:
   - **Maps Embed API** (iframe Mapa + Street View)
   - **Maps JavaScript API** (dashboard e pontos de iluminação)
   - **Street View Static API** (checagem `streetview/metadata` no PHP)
4. Crie uma chave de API. Para o **dashboard no navegador**, permita **HTTP referrers** (`https://localhost/*`, domínio de produção). O PHP (`streetview_check`) chama o Google no servidor — se a chave for só por referrer, use restrição por API ou uma segunda chave para o servidor.
5. Em `includes/config.local.php` (dev) ou no servidor:

```php
define('GOOGLE_MAPS_API_KEY', 'SUA_CHAVE_AQUI');
```

### Map ID (opcional — dashboard com Advanced Markers)

Sem Map ID, o dashboard usa **marcadores clássicos** (círculos coloridos nos postes). Com Map ID, ativa `libraries=marker` e marcadores avançados (visual igual ao Leaflet nos postes).

1. Google Cloud Console → **Google Maps Platform** → **Map Management** → **Create map ID** → tipo **JavaScript**.
2. Em `includes/config.local.php`:

```php
define('GOOGLE_MAPS_MAP_ID', 'seu_map_id_aqui');
```

| Dashboard | Sem `GOOGLE_MAPS_MAP_ID` | Com `GOOGLE_MAPS_MAP_ID` |
|-----------|--------------------------|----------------------------|
| Chamados | Pin clássico vermelho | Advanced Marker |
| Postes | Círculos verde/vermelho/cinza | Spans CSS (`ponto-marker--*`) |
| API JS | `key` + `callback` | + `libraries=marker` |

## Onde a chave é usada

| Área | Com chave | Sem chave |
|------|-----------|-----------|
| Novo chamado / formulário OS | Google Embed (Mapa + Street View) | Leaflet + svembed legado |
| Detalhe chamado (admin/operador) | Google Embed | Leaflet |
| OS detalhe (1 ponto) | iframe `embed/v1/place` (pin) | Leaflet |
| Dashboard admin/cliente (chamados, postes, combinado) | Google Maps JS + clusters | Leaflet + CARTO |
| Pontos de iluminação (lista admin/cliente + mapa dedicado) | Google Maps JS + clusters | Leaflet + CARTO |

## URLs geradas

- Street View: `https://www.google.com/maps/embed/v1/streetview?key=...&location=LAT,LNG`
- Mapa (com pin): `https://www.google.com/maps/embed/v1/place?key=...&q=LAT,LNG&zoom=16`
- Mapa (só centrado, sem pin): `embed/v1/view` — legado; o CRM usa `place` por padrão

## Verificação

1. Teste no navegador (logado):  
   `.../admin/geocode_nominatim_api.php?action=streetview_check&lat=-8.3986278&lon=-35.0644340`  
   → `{"ok":true,"available":true,...}`

2. No chamado, F12 → Rede: iframes com `/maps/embed/v1/` e `key=`.

3. Sem chave: `streetview_check` retorna `available: false` e o preview prefere **Mapa** Leaflet.

## Problemas comuns

- **`request_denied` no `streetview_check`:** a chave existe, mas o Google recusou a chamada **no servidor**. Causas frequentes:
  1. API **Street View Static API** não ativada no projeto.
  2. Restrição da chave por **sites (HTTP referrer)** — o PHP em `localhost` não envia referrer; use **Nenhuma** em “Restrições de aplicativo” e restrinja só pelas APIs (Maps Embed + Street View Static).
  3. Faturamento não ativo no projeto Google Cloud.
- **Iframe cinza (Street View):** referrer bloqueado ou Maps Embed API desativada.
- **Sempre mapa, nunca Street View:** metadata falha — corrija `request_denied` acima.
- **Dashboard sem tiles Google / overlay "não carregou corretamente":**
  1. **Maps JavaScript API** ativa e faturamento no projeto.
  2. Referrers na chave: `http://localhost/*` e `https://localhost/*` (e domínio de produção).
  3. Erro no console *"mapa sem ID de mapa válido"* — remova `GOOGLE_MAPS_MAP_ID` inválido ou crie um Map ID válido no Cloud Console (ver seção acima).
  4. Sem chave API, o dashboard usa Leaflet (CARTO/unpkg).
- **Windows sem mapa (fallback Leaflet):** ver bloqueio de `unpkg.com` / `cartocdn.com`.
