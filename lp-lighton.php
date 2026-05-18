<?php
if (is_file(__DIR__ . '/includes/config.local.php')) {
    require __DIR__ . '/includes/config.local.php';
}
require __DIR__ . '/includes/lp_config.php';
$lpWhatsAppHref = lp_whatsapp_href();
$lpLoginUrl     = (string) LP_LOGIN_URL;
$lpBrand        = (string) LP_BRAND_NAME;
$lpApp          = (string) LP_APP_NAME;
$lpTagline      = (string) LP_TAGLINE;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($lpBrand) ?> | <?= htmlspecialchars($lpTagline) ?> — CRM para empresas de iluminação pública</title>
    <meta name="description" content="<?= htmlspecialchars($lpBrand) ?> centraliza chamados georreferenciados, pontos de luz, app do técnico, boletim de medição (BM) e portal do cliente para operadoras de iluminação pública.">
    <link rel="icon" type="image/png" href="assets/img/lighton-icon.png">
    <link rel="apple-touch-icon" href="assets/img/lighton-icon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-900: #060f24;
            --bg-800: #0c1d3f;
            --bg-700: #132e61;
            --primary: #4f8dff;
            --primary-soft: #8cb5ff;
            --indigo: #6f56f7;
            --text: #122241;
            --text-soft: #4e6388;
            --white: #ffffff;
            --line: #d8e4ff;
            --surface: #f3f7ff;
            --radius: 16px;
            --shadow: 0 14px 30px rgba(20, 45, 92, 0.12);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            font-family: "Inter", Arial, sans-serif;
            color: var(--text);
            background: var(--surface);
            line-height: 1.5;
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        .container {
            width: min(1160px, 92%);
            margin: 0 auto;
        }

        .section {
            padding: 88px 0;
        }

        .section-head {
            margin-bottom: 28px;
        }

        .section-head h2 {
            font-size: clamp(1.5rem, 2.8vw, 2.3rem);
            margin-bottom: 10px;
        }

        .section-head p {
            color: var(--text-soft);
            max-width: 70ch;
        }

        .grid {
            display: grid;
            gap: 16px;
        }

        .cards-3 {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .cards-4 {
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }

        .cards-2 {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .card {
            background: var(--white);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 22px;
            box-shadow: var(--shadow);
            transition: transform 0.22s ease, box-shadow 0.22s ease, border-color 0.22s ease;
            animation: rise 0.6s ease both;
        }

        .card:hover {
            transform: translateY(-4px);
            border-color: #bed4ff;
            box-shadow: 0 22px 38px rgba(19, 45, 95, 0.16);
        }

        .card h3 {
            font-size: 1.05rem;
            margin-bottom: 8px;
        }

        .card p {
            color: var(--text-soft);
        }

        .site-header {
            position: sticky;
            top: 0;
            z-index: 50;
            background: rgba(6, 15, 36, 0.84);
            backdrop-filter: blur(8px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.11);
        }

        .nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            min-height: 74px;
            gap: 14px;
        }

        .brand {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            color: #eff4ff;
            font-size: 1rem;
            letter-spacing: 0.2px;
        }

        .brand span {
            color: #88b3ff;
        }

        .brand img {
            width: 44px;
            height: 44px;
            object-fit: contain;
            display: block;
        }

        .menu {
            display: flex;
            align-items: center;
            gap: 20px;
            color: #d0ddfb;
            font-size: 0.94rem;
        }

        .menu a:hover {
            color: #ffffff;
        }

        .menu-toggle {
            display: none;
            background: transparent;
            border: 1px solid rgba(255, 255, 255, 0.25);
            color: #fff;
            padding: 8px 10px;
            border-radius: 10px;
            cursor: pointer;
            font-size: 1.05rem;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 11px;
            border: 1px solid transparent;
            padding: 11px 17px;
            font-weight: 600;
            transition: 0.2s ease;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--indigo));
            color: #fff;
        }

        .btn-primary:hover {
            filter: brightness(1.08);
            transform: translateY(-1px);
        }

        .btn-outline {
            border-color: rgba(255, 255, 255, 0.35);
            color: #fff;
        }

        .btn-outline:hover {
            border-color: rgba(255, 255, 255, 0.85);
        }

        .hero {
            padding: 84px 0 92px;
            background:
                radial-gradient(circle at 10% 0%, rgba(112, 154, 255, 0.35) 0%, rgba(112, 154, 255, 0) 40%),
                radial-gradient(circle at 90% 10%, rgba(111, 86, 247, 0.33) 0%, rgba(111, 86, 247, 0) 42%),
                linear-gradient(145deg, var(--bg-900) 0%, var(--bg-800) 54%, var(--bg-700) 100%);
            color: #eaf1ff;
        }

        .hero-grid {
            display: grid;
            grid-template-columns: 1.05fr 1fr;
            gap: 30px;
            align-items: center;
        }

        .kicker {
            display: inline-block;
            margin-bottom: 14px;
            border-radius: 999px;
            border: 1px solid rgba(255, 255, 255, 0.25);
            color: #d5e3ff;
            background: rgba(255, 255, 255, 0.06);
            padding: 8px 12px;
            font-size: 0.83rem;
        }

        .hero h1 {
            font-size: clamp(2rem, 4.4vw, 3.3rem);
            line-height: 1.12;
            margin-bottom: 14px;
        }

        .hero .subtitle {
            font-size: 1.06rem;
            color: #c8d9ff;
            max-width: 63ch;
        }

        .hero-actions {
            margin-top: 22px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .hero-badges {
            margin-top: 18px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .hero-badges span {
            border: 1px solid rgba(255, 255, 255, 0.24);
            background: rgba(255, 255, 255, 0.08);
            border-radius: 999px;
            padding: 7px 12px;
            font-size: 0.86rem;
            color: #dee8ff;
        }

        .mockup {
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 18px;
            background: linear-gradient(180deg, rgba(10, 24, 50, 0.96), rgba(12, 32, 66, 0.96));
            box-shadow: 0 24px 50px rgba(0, 0, 0, 0.35);
            padding: 16px;
        }

        .screenshot-frame {
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 18px;
            background: linear-gradient(180deg, rgba(10, 24, 50, 0.96), rgba(12, 32, 66, 0.96));
            box-shadow: 0 24px 50px rgba(0, 0, 0, 0.35);
            padding: 12px;
        }

        .screenshot-frame img {
            width: 100%;
            display: block;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.14);
        }

        .mockup-top {
            display: flex;
            gap: 8px;
            margin-bottom: 12px;
        }

        .dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #8cb5ff;
        }

        .mockup-grid {
            display: grid;
            grid-template-columns: 1.2fr 0.8fr;
            gap: 10px;
        }

        .panel {
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.14);
            border-radius: 12px;
            padding: 10px;
        }

        .panel h4 {
            font-size: 0.78rem;
            color: #cad9ff;
            margin-bottom: 8px;
            font-weight: 600;
        }

        .bars {
            display: flex;
            align-items: flex-end;
            gap: 6px;
            height: 76px;
        }

        .bars span {
            flex: 1;
            border-radius: 8px 8px 3px 3px;
            background: linear-gradient(180deg, #8db5ff, #4f8dff);
        }

        .bars span:nth-child(1) { height: 48%; }
        .bars span:nth-child(2) { height: 72%; }
        .bars span:nth-child(3) { height: 56%; }
        .bars span:nth-child(4) { height: 88%; }
        .bars span:nth-child(5) { height: 64%; }

        .ticket-list {
            display: grid;
            gap: 7px;
        }

        .ticket {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            background: rgba(255, 255, 255, 0.07);
            border-radius: 10px;
            padding: 8px;
            font-size: 0.78rem;
            color: #d8e5ff;
        }

        .status {
            font-size: 0.72rem;
            padding: 4px 8px;
            border-radius: 999px;
            font-weight: 600;
        }

        .status-andamento { background: rgba(86, 154, 255, 0.24); color: #9cc4ff; }
        .status-concluido { background: rgba(83, 201, 145, 0.2); color: #90efc2; }
        .status-pendente { background: rgba(241, 176, 69, 0.2); color: #ffcb77; }

        .kpi-row {
            margin-top: 10px;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
        }

        .kpi {
            text-align: center;
            border-radius: 10px;
            border: 1px solid rgba(255, 255, 255, 0.14);
            background: rgba(255, 255, 255, 0.05);
            padding: 8px 6px;
            color: #d8e5ff;
            font-size: 0.75rem;
        }

        .kpi strong {
            display: block;
            font-size: 0.95rem;
            color: #fff;
            margin-bottom: 4px;
        }

        .problem {
            background: #f9fbff;
        }

        .problem .card {
            border-left: 4px solid #87b3ff;
        }

        .solution-wrap {
            display: grid;
            gap: 18px;
            grid-template-columns: 1fr 1fr;
            align-items: start;
        }

        .solution-copy {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 24px;
        }

        .solution-copy p {
            color: var(--text-soft);
            margin-bottom: 12px;
            line-height: 1.68;
        }

        .benefits {
            list-style: none;
            display: grid;
            gap: 10px;
        }

        .benefits li {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 12px 14px;
            box-shadow: 0 8px 18px rgba(24, 49, 100, 0.08);
        }

        .benefits li::before {
            content: "✓";
            color: var(--primary);
            font-weight: 700;
            margin-right: 8px;
        }

        .module-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: grid;
            place-items: center;
            margin-bottom: 10px;
            background: linear-gradient(140deg, #e7efff, #d9e6ff);
            color: #2f5fae;
            font-weight: 800;
        }

        .screenshots {
            background: #f7faff;
        }

        .shot-carousel {
            position: relative;
        }

        .shot-viewport {
            overflow: hidden;
            border-radius: 18px;
        }

        .shot-track {
            display: flex;
            transition: transform 0.35s ease;
            will-change: transform;
        }

        .shot-slide {
            min-width: 100%;
            display: grid;
            gap: 14px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .shot-card {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 16px;
            box-shadow: var(--shadow);
            padding: 12px;
        }

        .shot-card img {
            width: 100%;
            border-radius: 10px;
            display: block;
        }

        .shot-card p {
            margin-top: 9px;
            color: var(--text-soft);
            font-size: 0.9rem;
        }

        .shot-card h3 {
            margin-top: 10px;
            margin-bottom: 6px;
            font-size: 1rem;
        }

        .shot-functions {
            margin-top: 8px;
            padding-left: 18px;
            color: var(--text-soft);
            font-size: 0.88rem;
            line-height: 1.6;
        }

        .shot-controls {
            margin-top: 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
        }

        .shot-buttons {
            display: flex;
            gap: 8px;
        }

        .shot-btn {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            border: 1px solid #cfe0ff;
            background: #ffffff;
            color: #214993;
            font-size: 1.1rem;
            cursor: pointer;
        }

        .shot-dots {
            display: flex;
            gap: 6px;
        }

        .shot-dot {
            width: 9px;
            height: 9px;
            border-radius: 50%;
            background: #c1d5ff;
            border: 0;
            cursor: pointer;
        }

        .shot-dot.active {
            background: #4f8dff;
        }

        .lighting-section {
            background: linear-gradient(180deg, #f8fbff 0%, #eef4ff 100%);
        }

        .lighting-wrap {
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            gap: 16px;
            align-items: stretch;
        }

        .lighting-panel {
            background: linear-gradient(180deg, #0d2147 0%, #123369 100%);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 16px;
            padding: 16px;
            color: #eaf2ff;
            box-shadow: 0 20px 40px rgba(14, 35, 74, 0.3);
        }

        .lighting-panel h3 {
            margin-bottom: 12px;
            font-size: 1.05rem;
        }

        .lighting-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .lighting-metric {
            border: 1px solid rgba(255, 255, 255, 0.18);
            background: rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 12px;
        }

        .lighting-metric strong {
            display: block;
            font-size: 1.1rem;
            margin-bottom: 4px;
            color: #ffffff;
        }

        .lighting-metric span {
            font-size: 0.82rem;
            color: #cfe0ff;
        }

        .lighting-list {
            margin-top: 12px;
            display: grid;
            gap: 8px;
        }

        .lighting-list div {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
            font-size: 0.83rem;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.14);
            padding: 8px 10px;
        }

        .lighting-tag {
            border-radius: 999px;
            padding: 4px 8px;
            font-size: 0.72rem;
            font-weight: 600;
            background: rgba(141, 181, 255, 0.22);
            color: #b9d4ff;
        }

        .field-section {
            background: linear-gradient(180deg, #eef4ff 0%, #f8fbff 100%);
        }

        .field-wrap {
            display: grid;
            grid-template-columns: 0.95fr 1.05fr;
            gap: 20px;
            align-items: center;
        }

        .phone-mock {
            max-width: 320px;
            margin: 0 auto;
            border: 1px solid var(--line);
            border-radius: 28px;
            padding: 14px 12px 18px;
            background: linear-gradient(180deg, #0d2147 0%, #123369 100%);
            box-shadow: 0 24px 48px rgba(14, 35, 74, 0.28);
            color: #eaf2ff;
        }

        .phone-mock .screen {
            border-radius: 16px;
            border: 1px solid rgba(255, 255, 255, 0.14);
            background: rgba(255, 255, 255, 0.06);
            padding: 12px;
            min-height: 280px;
        }

        .phone-mock h3 {
            font-size: 0.95rem;
            margin-bottom: 10px;
        }

        .phone-mock ul {
            list-style: none;
            display: grid;
            gap: 8px;
            font-size: 0.84rem;
            color: #cfe0ff;
        }

        .phone-mock li::before {
            content: "→ ";
            color: #8cb5ff;
        }

        .medicao-section {
            background: #f9fbff;
        }

        .medicao-wrap {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            align-items: start;
        }

        .timeline {
            position: relative;
            display: grid;
            gap: 12px;
        }

        .timeline::before {
            content: "";
            position: absolute;
            left: 17px;
            top: 10px;
            bottom: 10px;
            width: 2px;
            background: #c8dafd;
        }

        .step {
            position: relative;
            padding-left: 48px;
        }

        .step .dot-step {
            position: absolute;
            left: 8px;
            top: 20px;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: linear-gradient(180deg, var(--primary), var(--indigo));
            border: 3px solid #eef4ff;
        }

        .step .num {
            font-size: 0.83rem;
            color: #3f5f9d;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .indicator-grid {
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }

        .indicator-card {
            text-align: center;
            padding-top: 26px;
            padding-bottom: 26px;
        }

        .indicator-card strong {
            display: block;
            color: #214993;
            margin-bottom: 8px;
            font-size: 1.1rem;
        }

        .implant-grid {
            grid-template-columns: repeat(5, minmax(0, 1fr));
        }

        .implant-card {
            text-align: center;
        }

        .implant-card .n {
            width: 34px;
            height: 34px;
            margin: 0 auto 9px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            font-weight: 700;
            color: #fff;
            background: linear-gradient(145deg, var(--primary), var(--indigo));
        }

        .cta {
            background: linear-gradient(130deg, #09193b 0%, #123a79 48%, #4527a4 100%);
            color: #edf3ff;
        }

        .cta-box {
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 20px;
            padding: 42px 24px;
            text-align: center;
            backdrop-filter: blur(2px);
        }

        .cta-box h2 {
            font-size: clamp(1.5rem, 3vw, 2.3rem);
            margin-bottom: 10px;
        }

        .cta-box p {
            color: #ccdcff;
            margin-bottom: 20px;
        }

        .cta-actions {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .faq-list {
            display: grid;
            gap: 10px;
        }

        .faq-list details {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 12px 14px;
        }

        .faq-list summary {
            cursor: pointer;
            font-weight: 600;
        }

        .faq-list p {
            margin-top: 9px;
            color: var(--text-soft);
        }

        .footer {
            background: #060f24;
            color: #d5e1fa;
            padding: 18px 0;
        }

        .footer .wrap {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        @keyframes rise {
            from {
                opacity: 0;
                transform: translateY(8px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @media (max-width: 1060px) {
            .cards-4 {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .indicator-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .implant-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }

        @media (max-width: 900px) {
            .menu {
                position: fixed;
                top: 74px;
                right: 4%;
                width: min(320px, 92%);
                background: rgba(11, 28, 58, 0.98);
                border: 1px solid rgba(255, 255, 255, 0.12);
                border-radius: 14px;
                padding: 14px;
                display: none;
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }

            .menu.open {
                display: flex;
            }

            .menu-toggle {
                display: inline-flex;
            }

            .hide-mobile {
                display: none;
            }

            .hero-grid,
            .solution-wrap,
            .lighting-wrap,
            .field-wrap,
            .medicao-wrap,
            .cards-3,
            .cards-2 {
                grid-template-columns: 1fr;
            }

            .shot-slide {
                grid-template-columns: 1fr;
            }

            .mockup-grid {
                grid-template-columns: 1fr;
            }

            .section {
                padding: 66px 0;
            }
        }

        @media (max-width: 680px) {
            .cards-4,
            .indicator-grid,
            .implant-grid {
                grid-template-columns: 1fr;
            }

            .hero {
                padding-top: 60px;
                padding-bottom: 72px;
            }
        }
    </style>
</head>
<body>
    <header class="site-header">
        <div class="container nav">
            <a href="#inicio" class="brand">
                <img src="assets/img/lighton-logo-transparent.png" alt="Logo <?= htmlspecialchars($lpBrand) ?>">
                <strong><?= htmlspecialchars($lpBrand) ?></strong>
            </a>
            <nav class="menu" id="menu">
                <a href="#problemas">Desafios</a>
                <a href="#solucao">Solução</a>
                <a href="#modulos">Módulos</a>
                <a href="#campo">App técnico</a>
                <a href="#medicao">Medição BM</a>
                <a href="#telas">Telas</a>
                <a href="#implantacao">Implantação</a>
                <a href="#faq">FAQ</a>
            </nav>
            <div class="hero-actions">
                <a href="#contato" class="btn btn-outline hide-mobile">Agendar demonstração</a>
                <button class="menu-toggle" id="menuToggle" aria-label="Abrir menu">☰</button>
            </div>
        </div>
    </header>

    <main>
        <section class="hero" id="inicio">
            <div class="container hero-grid">
                <div>
                    <span class="kicker">CRM SaaS para operadoras de iluminação pública · app <?= htmlspecialchars($lpApp) ?></span>
                    <h1>Gestão completa da sua operação de iluminação pública</h1>
                    <p class="subtitle">
                        Chamados georreferenciados, equipes em campo, pontos de luz no mapa e boletim de medição (BM) —
                        tudo integrado para você cumprir contrato com rastreabilidade entre escritório, técnicos e cliente público.
                    </p>
                    <div class="hero-actions">
                        <a href="#contato" class="btn btn-primary">Agendar demonstração</a>
                        <a href="#modulos" class="btn btn-outline">Ver funcionalidades</a>
                    </div>
                    <div class="hero-badges">
                        <span>Chamados com mapa</span>
                        <span>App do técnico</span>
                        <span>Boletim BM</span>
                        <span>Portal do cliente</span>
                        <span>Auditoria completa</span>
                    </div>
                </div>

                <div class="screenshot-frame" aria-label="Dashboard <?= htmlspecialchars($lpBrand) ?>">
                    <img src="assets/img/admin-dashboard.png" alt="Dashboard <?= htmlspecialchars($lpBrand) ?>">
                </div>
            </div>
        </section>

        <section class="section problem" id="problemas">
            <div class="container">
                <div class="section-head">
                    <h2>Sua operação ainda depende de planilhas, WhatsApp e processos manuais?</h2>
                </div>
                <div class="grid cards-3">
                    <article class="card"><h3>Planilhas paralelas</h3><p>Chamados, materiais e medição em arquivos desconectados, sem visão única.</p></article>
                    <article class="card"><h3>WhatsApp como sistema</h3><p>Despacho e retorno de campo sem registro formal nem histórico auditável.</p></article>
                    <article class="card"><h3>Sem geolocalização</h3><p>Endereço impreciso atrasa equipes e dificulta comprovar atendimento no contrato.</p></article>
                    <article class="card"><h3>BM e medição manual</h3><p>Fechamento mensal lento, sujeito a erro e sem portal para o cliente público.</p></article>
                    <article class="card"><h3>Cliente sem portal</h3><p>Prefeitura ou contratante sem transparência sobre fila, status e medição.</p></article>
                    <article class="card"><h3>Técnicos sem app</h3><p>Equipe em campo sem fluxo padronizado, anexos e materiais no mesmo chamado.</p></article>
                </div>
            </div>
        </section>

        <section class="section" id="solucao">
            <div class="container">
                <div class="section-head">
                    <h2>A <?= htmlspecialchars($lpBrand) ?> une escritório, campo e cliente em um único fluxo</h2>
                </div>
                <div class="solution-wrap">
                    <div class="solution-copy">
                        <p>
                            A <?= htmlspecialchars($lpBrand) ?> conecta a gestão da sua empresa, os técnicos em campo e o portal do cliente contratante
                            (prefeitura ou órgão público) em uma plataforma pensada para iluminação pública.
                        </p>
                        <p>
                            Cada chamado segue status, prioridade, responsável e geolocalização — do registro à execução,
                            aprovação e exportação do boletim de medição (BM) para prestação de contas do contrato.
                        </p>
                    </div>
                    <ul class="benefits">
                        <li>Chamados e OS padronizados</li>
                        <li>Mapa de pontos e chamados</li>
                        <li>App PWA para técnicos</li>
                        <li>Medição BM import/export</li>
                        <li>Auditoria e relatórios</li>
                    </ul>
                </div>
            </div>
        </section>

        <section class="section" id="modulos">
            <div class="container">
                <div class="section-head">
                    <h2>Módulos para a operação de iluminação pública</h2>
                    <p>Tudo o que sua empresa precisa para cumprir contrato com rastreabilidade — do chamado ao boletim BM.</p>
                </div>
                <div class="grid cards-4">
                    <article class="card"><div class="module-icon">CH</div><h3>Chamados e OS</h3><p>Prioridade, status, endereço geo, anexos, materiais e histórico completo.</p></article>
                    <article class="card"><div class="module-icon">MP</div><h3>Mapa de pontos</h3><p>Cadastro de luminárias e postes, importação em lote, fotos e rotas.</p></article>
                    <article class="card"><div class="module-icon">CI</div><h3>Gestão de Chamados Internos</h3><p>Organize demandas entre setores com responsáveis e prazos definidos.</p></article>
                    <article class="card"><div class="module-icon">PG</div><h3>Painel do Gestor</h3><p>Visualização estratégica de desempenho da operação em tempo real.</p></article>
                    <article class="card"><div class="module-icon">PP</div><h3>Perfis e Permissões</h3><p>Acessos segmentados por secretaria, cargo e nível de responsabilidade.</p></article>
                    <article class="card"><div class="module-icon">RG</div><h3>Relatórios Gerenciais</h3><p>Consolidação de dados para reuniões, decisões e prestação de contas.</p></article>
                    <article class="card"><div class="module-icon">HA</div><h3>Histórico e Auditoria</h3><p>Registro de movimentações para rastrear ações e manter conformidade.</p></article>
                    <article class="card"><div class="module-icon">GS</div><h3>Gestão por Secretaria</h3><p>Comparativos de resultados por setor para gestão orientada por metas.</p></article>
                    <article class="card"><div class="module-icon">IP</div><h3>Controle de Iluminação Pública</h3><p>Gerencie pontos, chamados, equipes em campo e status de manutenção por região.</p></article>
                </div>
            </div>
        </section>

        <section class="section screenshots" id="telas">
            <div class="container">
                <div class="section-head">
                    <h2>Módulos do OnLight na prática</h2>
                    <p>Explore as telas reais da plataforma e veja, de forma objetiva, como cada módulo organiza a operação municipal com mais controle, agilidade e rastreabilidade.</p>
                </div>
                <div class="shot-carousel" id="shotCarousel">
                    <div class="shot-viewport">
                        <div class="shot-track" id="shotTrack">
                            <div class="shot-slide">
                                <article class="shot-card">
                                    <img src="assets/img/admin-dashboard.png" alt="Dashboard do sistema OnLight">
                                    <h3>Módulo Dashboard Executivo</h3>
                                    <p>Painel central com visão da operação, indicadores e mapa de chamados em tempo real.</p>
                                    <ul class="shot-functions">
                                        <li>Resumo de chamados por status e prioridade.</li>
                                        <li>Mapa operacional para leitura rápida de demandas.</li>
                                        <li>Suporte à decisão para coordenação e gestão.</li>
                                    </ul>
                                </article>
                                <article class="shot-card">
                                    <img src="assets/img/admin-cliente-detalhe.png" alt="Tela de detalhes do cliente">
                                    <h3>Módulo Gestão de Clientes</h3>
                                    <p>Cadastro detalhado para organizar contratos, unidades e relacionamento operacional.</p>
                                    <ul class="shot-functions">
                                        <li>Dados cadastrais completos por cliente/unidade.</li>
                                        <li>Vínculo com chamados, contratos e catálogo.</li>
                                        <li>Base estruturada para atendimento padronizado.</li>
                                    </ul>
                                </article>
                            </div>
                            <div class="shot-slide">
                                <article class="shot-card">
                                    <img src="assets/img/admin-chamados.png" alt="Gestão de chamados no OnLight">
                                    <h3>Módulo Gestão de Chamados</h3>
                                    <p>Controle operacional da fila de atendimento com filtros, SLA e priorização de execução.</p>
                                    <ul class="shot-functions">
                                        <li>Filtros avançados por período, status e prioridade.</li>
                                        <li>Distribuição de demandas por equipe responsável.</li>
                                        <li>Acompanhamento de prazos e produtividade.</li>
                                    </ul>
                                </article>
                                <article class="shot-card">
                                    <img src="assets/img/admin-pontos-iluminacao.png" alt="Mapa de pontos de iluminação">
                                    <h3>Módulo Iluminação Pública</h3>
                                    <p>Mapa técnico com geolocalização de ativos para planejamento, manutenção e fiscalização.</p>
                                    <ul class="shot-functions">
                                        <li>Visualização geográfica de postes e luminárias.</li>
                                        <li>Planejamento por área e concentração de ocorrências.</li>
                                        <li>Apoio ao despacho de equipes em campo.</li>
                                    </ul>
                                </article>
                            </div>
                        </div>
                    </div>
                    <div class="shot-controls">
                        <div class="shot-buttons">
                            <button class="shot-btn" id="shotPrev" aria-label="Slide anterior">‹</button>
                            <button class="shot-btn" id="shotNext" aria-label="Próximo slide">›</button>
                        </div>
                        <div class="shot-dots" id="shotDots">
                            <button class="shot-dot active" data-slide="0" aria-label="Ir para slide 1"></button>
                            <button class="shot-dot" data-slide="1" aria-label="Ir para slide 2"></button>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="section lighting-section" id="iluminacao">
            <div class="container">
                <div class="section-head">
                    <h2>Controle de iluminação pública integrado ao OnLight</h2>
                    <p>
                        Cadastre pontos de luz, acompanhe falhas, distribua ordens de serviço e monitore execução por bairro, equipe e prazo.
                        Tudo com rastreabilidade, histórico e visão gerencial em tempo real.
                    </p>
                </div>
                <div class="lighting-wrap">
                    <div class="lighting-panel">
                        <h3>Painel operacional de iluminação</h3>
                        <div class="lighting-grid">
                            <div class="lighting-metric"><strong>312</strong><span>Pontos cadastrados</span></div>
                            <div class="lighting-metric"><strong>27</strong><span>Ordens em aberto</span></div>
                            <div class="lighting-metric"><strong>19</strong><span>Em execução</span></div>
                            <div class="lighting-metric"><strong>8</strong><span>Concluídas hoje</span></div>
                        </div>
                        <div class="lighting-list">
                            <div><span>Rua das Acácias - Poste 128</span><span class="lighting-tag">Em andamento</span></div>
                            <div><span>Praça Central - Refletor</span><span class="lighting-tag">Pendente</span></div>
                            <div><span>Av. Brasil - Luminária LED</span><span class="lighting-tag">Concluído</span></div>
                        </div>
                    </div>
                    <div class="grid">
                        <article class="card">
                            <h3>Cadastro de pontos e ativos</h3>
                            <p>Organize postes, luminárias, bairros e equipes com identificação para facilitar manutenção preventiva e corretiva.</p>
                        </article>
                        <article class="card">
                            <h3>Ordens de serviço com SLA</h3>
                            <p>Abra e acompanhe ordens por prioridade, prazo e responsável, com histórico completo da execução.</p>
                        </article>
                        <article class="card">
                            <h3>Mapa de ocorrências por região</h3>
                            <p>Visualize regiões com maior volume de falhas para priorizar investimento e reduzir reincidências.</p>
                        </article>
                        <article class="card">
                            <h3>Indicadores de eficiência energética</h3>
                            <p>Acompanhe evolução de atendimento, substituição de ativos e desempenho da operação de iluminação.</p>
                        </article>
                    </div>
                </div>
            </div>
        </section>

        <section class="section" id="como-funciona">
            <div class="container">
                <div class="section-head">
                    <h2>Como funciona</h2>
                    <p>Da abertura da demanda até a auditoria final, tudo em etapas claras e rastreáveis.</p>
                </div>
                <div class="timeline">
                    <article class="card step"><span class="dot-step"></span><div class="num">01</div><h3>Cidadão ou servidor registra a demanda</h3><p>A solicitação entra no sistema com dados e categoria.</p></article>
                    <article class="card step"><span class="dot-step"></span><div class="num">02</div><h3>Sistema classifica e encaminha</h3><p>Triagem automática para secretaria e fila correta.</p></article>
                    <article class="card step"><span class="dot-step"></span><div class="num">03</div><h3>Setor responsável executa</h3><p>A equipe atua com prioridade, prazos e atualização de status.</p></article>
                    <article class="card step"><span class="dot-step"></span><div class="num">04</div><h3>Gestor acompanha indicadores</h3><p>Dashboard mostra desempenho e pendências em tempo real.</p></article>
                    <article class="card step"><span class="dot-step"></span><div class="num">05</div><h3>Histórico fica registrado para auditoria</h3><p>Todas as ações ficam armazenadas para controle e transparência.</p></article>
                </div>
            </div>
        </section>

        <section class="section" id="indicadores">
            <div class="container">
                <div class="section-head">
                    <h2>Indicadores que apoiam decisões da gestão</h2>
                </div>
                <div class="grid indicator-grid">
                    <article class="card indicator-card"><strong>Mais controle</strong><p>das demandas abertas e em andamento</p></article>
                    <article class="card indicator-card"><strong>Menos retrabalho</strong><p>entre secretarias e equipes operacionais</p></article>
                    <article class="card indicator-card"><strong>Mais transparência</strong><p>com fluxo rastreável e histórico auditável</p></article>
                    <article class="card indicator-card"><strong>Decisões com dados</strong><p>por secretaria, setor, prazo e volume</p></article>
                </div>
            </div>
        </section>

        <section class="section" id="ideal-para">
            <div class="container">
                <div class="section-head">
                    <h2>Ideal para</h2>
                </div>
                <div class="grid cards-3">
                    <article class="card"><h3>Prefeituras</h3><p>Gestão central de atendimento e operação municipal.</p></article>
                    <article class="card"><h3>Secretarias municipais</h3><p>Controle de filas, tarefas e desempenho por área.</p></article>
                    <article class="card"><h3>Ouvidorias</h3><p>Registro e acompanhamento transparente das manifestações.</p></article>
                    <article class="card"><h3>Atendimento ao cidadão</h3><p>Fluxo padronizado com retorno e rastreabilidade.</p></article>
                    <article class="card"><h3>Obras e serviços urbanos</h3><p>Chamados com prioridade, setor responsável e prazo.</p></article>
                    <article class="card"><h3>Saúde, educação e assistência social</h3><p>Acompanhamento por demanda e visão por secretaria.</p></article>
                </div>
            </div>
        </section>

        <section class="section" id="implantacao">
            <div class="container">
                <div class="section-head">
                    <h2>Implantação assistida</h2>
                </div>
                <div class="grid implant-grid">
                    <article class="card implant-card"><span class="n">1</span><h3>Diagnóstico inicial</h3></article>
                    <article class="card implant-card"><span class="n">2</span><h3>Configuração dos setores</h3></article>
                    <article class="card implant-card"><span class="n">3</span><h3>Treinamento da equipe</h3></article>
                    <article class="card implant-card"><span class="n">4</span><h3>Início da operação</h3></article>
                    <article class="card implant-card"><span class="n">5</span><h3>Suporte contínuo</h3></article>
                </div>
            </div>
        </section>

        <section class="section cta" id="cta">
            <div class="container cta-box">
                <h2>Pronto para modernizar o atendimento da sua prefeitura?</h2>
                <p>Solicite uma demonstração e veja como o OnLight pode organizar protocolos, chamados e indicadores da sua gestão.</p>
                <div class="cta-actions">
                    <a class="btn btn-primary" href="https://wa.me/5500000000000" target="_blank" rel="noopener noreferrer">Falar no WhatsApp</a>
                    <a class="btn btn-outline" href="login.php">Acessar sistema</a>
                </div>
            </div>
        </section>

        <section class="section" id="faq">
            <div class="container">
                <div class="section-head">
                    <h2>Perguntas frequentes</h2>
                </div>
                <div class="faq-list">
                    <details><summary>O sistema funciona em nuvem?</summary><p>Sim, o OnLight é uma plataforma web e pode ser acessada com segurança em nuvem.</p></details>
                    <details><summary>É possível separar acessos por secretaria?</summary><p>Sim, o sistema possui perfis e permissões por secretaria, equipe e função.</p></details>
                    <details><summary>Existe histórico das movimentações?</summary><p>Sim, todas as ações e mudanças de status ficam registradas para auditoria.</p></details>
                    <details><summary>A equipe recebe treinamento?</summary><p>Sim, a implantação inclui treinamento e acompanhamento do início da operação.</p></details>
                    <details><summary>Funciona em celular?</summary><p>Sim, o sistema é responsivo e pode ser utilizado em smartphones e tablets.</p></details>
                    <details><summary>Pode ser adaptado ao fluxo da prefeitura?</summary><p>Sim, configuramos setores, categorias e regras conforme a realidade de cada gestão.</p></details>
                </div>
            </div>
        </section>
    </main>

    <footer class="footer">
        <div class="container wrap">
            <p>&copy; <?php echo date('Y'); ?> OnLight. Todos os direitos reservados.</p>
            <a href="login.php">Acessar sistema</a>
        </div>
    </footer>

    <script>
        const menuToggle = document.getElementById("menuToggle");
        const menu = document.getElementById("menu");

        menuToggle.addEventListener("click", () => {
            menu.classList.toggle("open");
        });

        menu.querySelectorAll("a").forEach((item) => {
            item.addEventListener("click", () => {
                menu.classList.remove("open");
            });
        });

        const shotTrack = document.getElementById("shotTrack");
        const shotPrev = document.getElementById("shotPrev");
        const shotNext = document.getElementById("shotNext");
        const shotDots = Array.from(document.querySelectorAll(".shot-dot"));

        let currentSlide = 0;
        const totalSlides = shotDots.length;

        function renderShotSlide(index) {
            currentSlide = (index + totalSlides) % totalSlides;
            shotTrack.style.transform = `translateX(-${currentSlide * 100}%)`;
            shotDots.forEach((dot, i) => dot.classList.toggle("active", i === currentSlide));
        }

        shotPrev?.addEventListener("click", () => renderShotSlide(currentSlide - 1));
        shotNext?.addEventListener("click", () => renderShotSlide(currentSlide + 1));
        shotDots.forEach((dot, i) => {
            dot.addEventListener("click", () => renderShotSlide(i));
        });
    </script>
</body>
</html>
