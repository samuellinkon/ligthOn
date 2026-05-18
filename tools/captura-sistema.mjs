import { chromium } from "playwright";

const base = "http://localhost/crm_prefeitura";
const credenciais = {
  email: "admin@crm.com",
  senha: "admin123",
};

const paginas = [
  { nome: "admin-dashboard", url: `${base}/admin/index.php` },
  { nome: "admin-cliente-detalhe", url: `${base}/admin/cliente_detalhe.php?id=12` },
  { nome: "admin-chamados", url: `${base}/admin/chamados.php` },
  { nome: "admin-pontos-iluminacao", url: `${base}/admin/pontos_iluminacao.php` },
  { nome: "admin-catalogo", url: `${base}/admin/catalogo.php?cliente_id=12` },
];

const login = `${base}/login.php`;
const outDir = "assets/img";

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
const page = await context.newPage();

try {
  await page.goto(login, { waitUntil: "domcontentloaded", timeout: 30000 });
  await page.fill("#email", credenciais.email);
  await page.fill("#senha", credenciais.senha);
  await Promise.all([
    page.waitForNavigation({ waitUntil: "domcontentloaded", timeout: 30000 }),
    page.click('button[type="submit"]'),
  ]);

  if (page.url().includes("login.php")) {
    throw new Error("Falha no login com admin@crm.com / admin123.");
  }

  for (const item of paginas) {
    try {
      await page.goto(item.url, { waitUntil: "domcontentloaded", timeout: 30000 });
      await page.waitForTimeout(1800);
      const path = `${outDir}/${item.nome}.png`;
      await page.screenshot({ path, fullPage: false, timeout: 45000 });
      console.log(`OK: ${path}`);
    } catch (err) {
      console.log(`ERRO: ${item.nome} -> ${err.message}`);
    }
  }
} finally {
  await browser.close();
}
