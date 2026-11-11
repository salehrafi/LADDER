<?php
/**
 * Homepage. Visitors see the landing page; a logged-in user goes straight to
 * their dashboard (or to the forced password change).
 */
require_once __DIR__ . '/includes/bootstrap.php';

if (isset($_SESSION['logged_in'], $_SESSION['role'])) {
    header('Location: ' . (!empty($_SESSION['must_change_password'])
        ? 'auth/first_login.php'
        : dashboard_for_role($_SESSION['role'])));
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="LADDER: Climb Higher. Achieve More. Build quizzes in minutes, run secure exams, and watch every student progress.">
    <meta name="theme-color" content="#0B1220">
    <title>LADDER | Climb Higher. Achieve More.</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy-950: #0B1220;
            --navy-900: #0F1B33;
            --navy-800: #16264A;
            --indigo: #4F46E5;
            --indigo-600: #4338CA;
            --blue: #2563EB;
            --sky: #38BDF8;
            --violet: #7C3AED;
            --green: #10B981;
            --amber: #F59E0B;
            --bg: #FFFFFF;
            --surface: #F7F8FC;
            --text: #0B1220;
            --text-2: #475569;
            --muted: #94A3B8;
            --line: rgba(15, 27, 51, 0.08);
            --line-2: rgba(15, 27, 51, 0.12);
            --grad: linear-gradient(135deg, #2563EB 0%, #4F46E5 50%, #7C3AED 100%);
            --shadow-sm: 0 1px 2px rgba(15, 23, 42, 0.06), 0 1px 1px rgba(15, 23, 42, 0.04);
            --shadow-md: 0 8px 24px -8px rgba(15, 23, 42, 0.14), 0 2px 6px rgba(15, 23, 42, 0.05);
            --shadow-xl: 0 40px 80px -24px rgba(30, 41, 99, 0.28), 0 16px 32px -16px rgba(15, 23, 42, 0.16);
            --radius: 14px;
            --font: "Inter", ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            --ease: cubic-bezier(0.22, 1, 0.36, 1);
        }

        *, *::before, *::after { box-sizing: border-box; }

        html { -webkit-text-size-adjust: 100%; }

        body {
            margin: 0;
            font-family: var(--font);
            color: var(--text);
            background: var(--bg);
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            overflow-x: hidden;
        }

        a { color: inherit; text-decoration: none; }
        :focus-visible { outline: 2px solid var(--indigo); outline-offset: 3px; border-radius: 8px; }

        .wrap { width: 100%; max-width: 1200px; margin: 0 auto; padding: 0 24px; }

        /* ---------- Background ---------- */
        .backdrop {
            position: absolute;
            inset: 0 0 auto 0;
            height: 980px;
            overflow: hidden;
            pointer-events: none;
            z-index: 0;
        }
        .backdrop::before {
            content: "";
            position: absolute;
            inset: 0;
            background-image:
                linear-gradient(to right, rgba(15, 27, 51, 0.045) 1px, transparent 1px),
                linear-gradient(to bottom, rgba(15, 27, 51, 0.045) 1px, transparent 1px);
            background-size: 56px 56px;
            -webkit-mask-image: radial-gradient(ellipse 70% 60% at 50% 30%, #000 30%, transparent 75%);
                    mask-image: radial-gradient(ellipse 70% 60% at 50% 30%, #000 30%, transparent 75%);
        }
        .orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(80px);
            opacity: 0.55;
        }
        .orb-1 { width: 560px; height: 560px; top: -180px; right: -120px; background: radial-gradient(circle, rgba(99, 102, 241, 0.55), transparent 65%); }
        .orb-2 { width: 480px; height: 480px; top: 180px; left: -200px; background: radial-gradient(circle, rgba(56, 189, 248, 0.38), transparent 65%); }
        .orb-3 { width: 420px; height: 420px; top: 420px; right: 18%; background: radial-gradient(circle, rgba(124, 58, 237, 0.25), transparent 65%); }

        /* ---------- Nav ---------- */
        .nav {
            position: sticky;
            top: 0;
            z-index: 50;
            background: rgba(255, 255, 255, 0.72);
            -webkit-backdrop-filter: saturate(180%) blur(14px);
                    backdrop-filter: saturate(180%) blur(14px);
            border-bottom: 1px solid transparent;
            transition: border-color 240ms, background-color 240ms;
        }
        .nav.scrolled { border-bottom-color: var(--line); background: rgba(255, 255, 255, 0.86); }
        .nav .wrap { display: flex; align-items: center; justify-content: space-between; height: 68px; gap: 16px; }

        .brand { display: inline-flex; align-items: center; gap: 10px; font-weight: 700; font-size: 16px; letter-spacing: -0.01em; color: var(--navy-950); white-space: nowrap; }
        .brand-mark {
            position: relative;
            display: grid;
            place-items: center;
            width: 32px;
            height: 32px;
            border-radius: 9px;
            background: var(--grad);
            box-shadow: 0 6px 16px -6px rgba(79, 70, 229, 0.7), inset 0 1px 0 rgba(255, 255, 255, 0.25);
        }
        .brand-mark svg { width: 18px; height: 18px; }
        .brand-name { font-weight: 800; letter-spacing: 0.14em; font-size: 15px; }
        .brand-name .dot { color: var(--indigo); }
        .tag-dot { color: var(--indigo); }

        .nav-actions { display: flex; align-items: center; gap: 8px; }

        .btn {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            height: 40px;
            padding: 0 18px;
            border-radius: 10px;
            font-family: inherit;
            font-weight: 600;
            font-size: 14px;
            letter-spacing: -0.005em;
            white-space: nowrap;
            border: 1px solid transparent;
            transition: transform 200ms var(--ease), box-shadow 200ms var(--ease), background-color 200ms, border-color 200ms, color 200ms;
        }
        .btn svg { width: 16px; height: 16px; transition: transform 200ms var(--ease); }
        .btn:hover svg { transform: translateX(2px); }

        .btn-ghost { color: var(--text-2); }
        .btn-ghost:hover { color: var(--navy-950); background: rgba(15, 27, 51, 0.05); }

        .btn-dark { color: #fff; background: var(--navy-950); box-shadow: var(--shadow-sm), inset 0 1px 0 rgba(255, 255, 255, 0.1); }
        .btn-dark:hover { background: var(--navy-800); transform: translateY(-1px); box-shadow: var(--shadow-md); }

        .btn-lg { height: 50px; padding: 0 24px; font-size: 15px; border-radius: 12px; }

        .btn-primary {
            color: #fff;
            background: var(--grad);
            box-shadow: 0 10px 24px -10px rgba(79, 70, 229, 0.75), inset 0 1px 0 rgba(255, 255, 255, 0.22);
        }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 16px 32px -12px rgba(79, 70, 229, 0.8), inset 0 1px 0 rgba(255, 255, 255, 0.22); }

        .btn-secondary { color: var(--navy-950); background: #fff; border-color: var(--line-2); box-shadow: var(--shadow-sm); }
        .btn-secondary:hover { transform: translateY(-2px); border-color: rgba(79, 70, 229, 0.35); box-shadow: var(--shadow-md); }

        /* ---------- Hero ---------- */
        main { position: relative; z-index: 1; }

        .hero { padding: 72px 0 96px; }
        .hero .wrap {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1.08fr);
            align-items: center;
            gap: 64px;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 5px 14px 5px 6px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.8);
            border: 1px solid var(--line-2);
            box-shadow: var(--shadow-sm);
            font-size: 13px;
            font-weight: 500;
            color: var(--text-2);
        }
        .eyebrow .pill {
            padding: 2px 9px;
            border-radius: 999px;
            background: var(--grad);
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .hero h1 {
            margin: 24px 0 20px;
            font-size: clamp(42px, 6.2vw, 76px);
            line-height: 1.02;
            font-weight: 800;
            letter-spacing: -0.045em;
            color: var(--navy-950);
        }
        .hero h1 .grad {
            background: var(--grad);
            -webkit-background-clip: text;
                    background-clip: text;
            color: transparent;
        }

        .lead {
            max-width: 500px;
            margin: 0 0 36px;
            font-size: 18px;
            line-height: 1.65;
            color: var(--text-2);
        }

        .cta { display: flex; flex-wrap: wrap; gap: 12px; }

        .trust {
            display: flex;
            flex-wrap: wrap;
            gap: 10px 22px;
            margin: 36px 0 0;
            padding: 0;
            list-style: none;
            font-size: 14px;
            color: var(--text-2);
        }
        .trust li { display: inline-flex; align-items: center; gap: 8px; }
        .trust svg { width: 16px; height: 16px; color: var(--indigo); flex: none; }

        /* ---------- Mockup ---------- */
        .stage { position: relative; perspective: 1800px; }

        .app {
            position: relative;
            border-radius: 18px;
            background: rgba(255, 255, 255, 0.92);
            border: 1px solid var(--line-2);
            box-shadow: var(--shadow-xl);
            overflow: hidden;
            transform: rotateY(-7deg) rotateX(4deg);
            transform-origin: center;
            transition: transform 700ms var(--ease);
        }
        .stage:hover .app { transform: rotateY(-3deg) rotateX(2deg); }

        .app-bar {
            display: flex;
            align-items: center;
            gap: 14px;
            height: 44px;
            padding: 0 16px;
            border-bottom: 1px solid var(--line);
            background: linear-gradient(#FBFCFE, #F5F7FB);
        }
        .dots { display: flex; gap: 6px; }
        .dots i { width: 10px; height: 10px; border-radius: 50%; background: #E2E8F0; }
        .dots i:nth-child(1) { background: #FCA5A5; }
        .dots i:nth-child(2) { background: #FCD34D; }
        .dots i:nth-child(3) { background: #86EFAC; }
        .url {
            flex: 1;
            max-width: 260px;
            margin: 0 auto;
            height: 24px;
            border-radius: 7px;
            background: #fff;
            border: 1px solid var(--line);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            font-size: 11px;
            color: var(--muted);
        }
        .url svg { width: 10px; height: 10px; }

        .app-body { display: grid; grid-template-columns: 56px 1fr; min-height: 420px; }

        .rail {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
            padding: 16px 0;
            border-right: 1px solid var(--line);
            background: #FAFBFD;
        }
        .rail span { width: 32px; height: 32px; border-radius: 9px; display: grid; place-items: center; color: var(--muted); }
        .rail span svg { width: 16px; height: 16px; }
        .rail span.on { background: rgba(79, 70, 229, 0.1); color: var(--indigo); }
        .rail .logo { background: var(--grad); color: #fff; margin-bottom: 6px; }

        .panel { padding: 20px; display: grid; gap: 14px; align-content: start; }

        .panel-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .panel-head h4 { margin: 0; font-size: 15px; font-weight: 700; letter-spacing: -0.01em; }
        .panel-head p { margin: 2px 0 0; font-size: 12px; color: var(--muted); }
        .chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
            color: var(--green);
            background: rgba(16, 185, 129, 0.1);
        }
        .chip::before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: currentColor; animation: pulse 2s infinite; }

        .stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; }
        .stat { padding: 12px; border-radius: 12px; border: 1px solid var(--line); background: #fff; }
        .stat small { display: block; font-size: 11px; color: var(--muted); font-weight: 500; }
        .stat strong { display: block; margin-top: 2px; font-size: 20px; font-weight: 700; letter-spacing: -0.02em; }
        .stat em { font-style: normal; font-size: 11px; font-weight: 600; color: var(--green); }

        .row { display: grid; grid-template-columns: 1.35fr 1fr; gap: 10px; }

        .card { padding: 14px; border-radius: 12px; border: 1px solid var(--line); background: #fff; }
        .card-title { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; font-size: 12px; font-weight: 600; }
        .card-title span { color: var(--muted); font-weight: 500; }

        .quiz { display: grid; grid-template-columns: 28px 1fr auto; align-items: center; gap: 10px; padding: 7px 0; }
        .quiz + .quiz { border-top: 1px dashed var(--line); }
        .quiz .ic { width: 28px; height: 28px; border-radius: 8px; display: grid; place-items: center; font-size: 11px; font-weight: 700; color: #fff; }
        .quiz b { display: block; font-size: 12px; font-weight: 600; line-height: 1.3; }
        .bar { height: 5px; margin-top: 6px; border-radius: 99px; background: #EEF1F7; overflow: hidden; }
        .bar i { display: block; height: 100%; width: var(--w); border-radius: inherit; transform-origin: left; animation: grow 1.4s var(--ease) both; animation-delay: 0.6s; }
        .quiz .pct { font-size: 12px; font-weight: 600; color: var(--text-2); font-variant-numeric: tabular-nums; }

        .ring-card { display: grid; place-items: center; text-align: center; }
        .ring { position: relative; width: 104px; height: 104px; }
        .ring svg { width: 100%; height: 100%; transform: rotate(-90deg); }
        .ring circle { fill: none; stroke-width: 9; }
        .ring .track { stroke: #EEF1F7; }
        .ring .val { stroke: url(#ringGrad); stroke-linecap: round; stroke-dasharray: 264; stroke-dashoffset: 34; animation: ring 1.6s var(--ease) both; animation-delay: 0.5s; }
        .ring-label { position: absolute; inset: 0; display: grid; place-items: center; align-content: center; }
        .ring-label strong { font-size: 22px; font-weight: 800; letter-spacing: -0.03em; line-height: 1; }
        .ring-label small { font-size: 10px; color: var(--muted); margin-top: 4px; }
        .ring-card p { margin: 10px 0 0; font-size: 11px; color: var(--muted); }

        .chart { display: flex; align-items: flex-end; gap: 6px; height: 56px; }
        .chart i { flex: 1; height: var(--h); border-radius: 4px 4px 2px 2px; background: linear-gradient(to top, rgba(79, 70, 229, 0.18), rgba(79, 70, 229, 0.45)); transform-origin: bottom; animation: rise 1s var(--ease) both; }
        .chart i:last-child { background: var(--grad); }
        .chart i:nth-child(1) { animation-delay: 0.7s; } .chart i:nth-child(2) { animation-delay: 0.76s; }
        .chart i:nth-child(3) { animation-delay: 0.82s; } .chart i:nth-child(4) { animation-delay: 0.88s; }
        .chart i:nth-child(5) { animation-delay: 0.94s; } .chart i:nth-child(6) { animation-delay: 1s; }
        .chart i:nth-child(7) { animation-delay: 1.06s; } .chart i:nth-child(8) { animation-delay: 1.12s; }
        .chart i:nth-child(9) { animation-delay: 1.18s; } .chart i:nth-child(10) { animation-delay: 1.24s; }

        /* Floating cards */
        .float {
            position: absolute;
            z-index: 2;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            border-radius: 14px;
            background: rgba(255, 255, 255, 0.94);
            -webkit-backdrop-filter: blur(10px);
                    backdrop-filter: blur(10px);
            border: 1px solid var(--line-2);
            box-shadow: var(--shadow-md), 0 24px 48px -20px rgba(30, 41, 99, 0.25);
            font-size: 13px;
            white-space: nowrap;
        }
        .float .fi { width: 34px; height: 34px; border-radius: 10px; display: grid; place-items: center; color: #fff; flex: none; }
        .float .fi svg { width: 17px; height: 17px; }
        .float b { display: block; font-weight: 700; font-size: 13px; line-height: 1.25; }
        .float small { display: block; color: var(--muted); font-size: 11.5px; }

        .float-a { left: -40px; bottom: 56px; animation: bob 6s ease-in-out infinite; }
        .float-b { right: -24px; top: 72px; animation: bob 7s ease-in-out infinite reverse; }
        .float-b .fi { background: var(--navy-950); }
        .float-a .fi { background: linear-gradient(135deg, #10B981, #059669); }

        .timer { font-variant-numeric: tabular-nums; font-weight: 700; color: var(--indigo); }

        /* ---------- Footer ---------- */
        footer { border-top: 1px solid var(--line); }
        footer .wrap { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; padding-top: 28px; padding-bottom: 28px; font-size: 14px; color: var(--text-2); }
        footer .brand { font-size: 14px; }
        footer .brand-mark { width: 26px; height: 26px; border-radius: 7px; }
        footer .brand-mark svg { width: 14px; height: 14px; }
        footer a.credit { color: var(--navy-950); font-weight: 600; background-image: linear-gradient(var(--indigo), var(--indigo)); background-size: 0 1px; background-position: 0 100%; background-repeat: no-repeat; transition: background-size 300ms var(--ease), color 200ms; }
        footer a.credit:hover { color: var(--indigo); background-size: 100% 1px; }

        /* ---------- Motion ---------- */
        .reveal { opacity: 0; transform: translateY(16px); animation: reveal 900ms var(--ease) forwards; animation-delay: var(--d, 0ms); }
        .reveal-stage { opacity: 0; transform: translateY(28px) scale(0.98); animation: reveal 1100ms var(--ease) forwards; animation-delay: 250ms; }

        @keyframes reveal { to { opacity: 1; transform: none; } }
        @keyframes grow { from { transform: scaleX(0); } }
        @keyframes rise { from { transform: scaleY(0); } }
        @keyframes ring { from { stroke-dashoffset: 264; } }
        @keyframes bob { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-10px); } }
        @keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.5); } 70%, 100% { box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); } }

        /* ---------- Responsive ---------- */
        @media (max-width: 1080px) {
            .hero .wrap { gap: 48px; }
            .float-a { left: -16px; }
            .float-b { right: -8px; }
        }

        @media (max-width: 960px) {
            .hero { padding: 48px 0 72px; }
            .hero .wrap { grid-template-columns: 1fr; gap: 56px; }
            .hero-copy { text-align: center; }
            .lead { margin-left: auto; margin-right: auto; }
            .cta, .trust { justify-content: center; }
            .stage { max-width: 640px; width: 100%; margin: 0 auto; }
            .app { transform: none; }
            .stage:hover .app { transform: none; }
            .backdrop { height: 1400px; }
        }

        @media (max-width: 600px) {
            .wrap { padding: 0 16px; }
            .nav .wrap { height: 60px; }
            .brand { font-size: 15px; }
            .btn { height: 38px; padding: 0 14px; }
            .btn-lg { height: 48px; padding: 0 20px; }
            .hero { padding-top: 36px; }
            .hero h1 { margin-top: 20px; }
            .lead { font-size: 16px; margin-bottom: 28px; }
            .cta { flex-direction: column; }
            .cta .btn { width: 100%; }
            .app-body { grid-template-columns: 1fr; min-height: 0; }
            .rail { display: none; }
            .panel { padding: 14px; gap: 10px; }
            .stat { padding: 10px; }
            .stat strong { font-size: 17px; }
            .row { grid-template-columns: 1fr; }
            .ring-card { grid-template-columns: auto 1fr; gap: 16px; text-align: left; justify-items: start; }
            .ring { width: 84px; height: 84px; }
            .float { padding: 10px 12px; }
            .float-a { left: 8px; bottom: -22px; }
            .float-b { right: 8px; top: -20px; }
            .float .fi { width: 30px; height: 30px; }
            footer .wrap { justify-content: center; text-align: center; }
        }

        @media (max-width: 380px) {
            .nav .brand { font-size: 14px; gap: 8px; }
            .nav-actions { gap: 4px; }
            .nav-actions .btn { padding: 0 10px; }
            .stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .stats .stat:last-child { grid-column: span 2; }
        }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                animation-delay: 0ms !important;
                transition-duration: 0.01ms !important;
            }
            .app, .stage:hover .app { transform: none; }
        }
    </style>
</head>

<body>
    <div class="backdrop" aria-hidden="true">
        <span class="orb orb-1"></span>
        <span class="orb orb-2"></span>
        <span class="orb orb-3"></span>
    </div>

    <header class="nav" id="nav">
        <div class="wrap">
            <a class="brand" href="index.php" aria-label="LADDER home">
                <span class="brand-mark" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3v18M16 3v18"/><path d="M8 17h8M8 12h8M8 7h8" opacity=".9"/></svg>
                </span>
                <span class="brand-name">LADDER</span>
            </a>
            <nav class="nav-actions" aria-label="Account">
                <a class="btn btn-ghost" href="auth/login.php">Log in</a>
                <a class="btn btn-dark" href="auth/registration.php">Sign up</a>
            </nav>
        </div>
    </header>

    <main>
        <section class="hero">
            <div class="wrap">
                <div class="hero-copy">
                    <span class="eyebrow reveal"><span class="pill">New</span> Introducing LADDER</span>
                    <h1 class="reveal" style="--d: 80ms">Climb Higher.<br><span class="grad">Achieve More.</span></h1>
                    <p class="lead reveal" style="--d: 160ms">LADDER turns every quiz into a step forward. Build assessments in minutes, run secure exams, and watch every student rise.</p>
                    <div class="cta reveal" style="--d: 240ms">
                        <a class="btn btn-primary btn-lg" href="auth/registration.php">
                            Get started free
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                        </a>
                        <a class="btn btn-secondary btn-lg" href="auth/login.php">Log in to your account</a>
                    </div>
                    <ul class="trust reveal" style="--d: 320ms">
                        <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.2 4.2L19 7"/></svg>Auto grading</li>
                        <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.2 4.2L19 7"/></svg>Exam mode</li>
                        <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.2 4.2L19 7"/></svg>CSV export</li>
                    </ul>
                </div>

                <div class="stage reveal-stage" aria-hidden="true">
                    <div class="app">
                        <div class="app-bar">
                            <div class="dots"><i></i><i></i><i></i></div>
                            <div class="url"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>app.ladder.io</div>
                            <div style="width:42px"></div>
                        </div>
                        <div class="app-body">
                            <div class="rail">
                                <span class="logo"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3v18M16 3v18M8 17h8M8 12h8M8 7h8"/></svg></span>
                                <span class="on"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg></span>
                                <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M9 4h10v16H5V8z"/><path d="M9 4v4H5"/><path d="M9 13h6M9 17h4"/></svg></span>
                                <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14a6.5 6.5 0 0 1 3.5 6"/></svg></span>
                                <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg></span>
                            </div>
                            <div class="panel">
                                <div class="panel-head">
                                    <div>
                                        <h4>Good morning, Saleh Rafi</h4>
                                        <p>3 quizzes live · 128 students climbing</p>
                                    </div>
                                    <span class="chip">Live</span>
                                </div>

                                <div class="stats">
                                    <div class="stat"><small>Avg. score</small><strong>84%</strong><em>+6.2%</em></div>
                                    <div class="stat"><small>Submissions</small><strong>1,248</strong><em>+142</em></div>
                                    <div class="stat"><small>Graded</small><strong>100%</strong><em>Instant</em></div>
                                </div>

                                <div class="row">
                                    <div class="card">
                                        <div class="card-title">Active quizzes <span>This week</span></div>
                                        <div class="quiz">
                                            <span class="ic" style="background:linear-gradient(135deg,#2563EB,#4F46E5)">DS</span>
                                            <div><b>Data Structures </b><div class="bar"><i style="--w:86%;background:linear-gradient(90deg,#2563EB,#4F46E5)"></i></div></div>
                                            <span class="pct">86%</span>
                                        </div>
                                        <div class="quiz">
                                            <span class="ic" style="background:linear-gradient(135deg,#7C3AED,#A855F7)">OS</span>
                                            <div><b>Operating Systems</b><div class="bar"><i style="--w:64%;background:linear-gradient(90deg,#7C3AED,#A855F7)"></i></div></div>
                                            <span class="pct">64%</span>
                                        </div>
                                        <div class="quiz">
                                            <span class="ic" style="background:linear-gradient(135deg,#0EA5E9,#38BDF8)">DB</span>
                                            <div><b>Advanced Database</b><div class="bar"><i style="--w:42%;background:linear-gradient(90deg,#0EA5E9,#38BDF8)"></i></div></div>
                                            <span class="pct">42%</span>
                                        </div>
                                    </div>
                                    <div class="card ring-card">
                                        <div class="ring">
                                            <svg viewBox="0 0 100 100">
                                                <defs><linearGradient id="ringGrad" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#2563EB"/><stop offset="1" stop-color="#7C3AED"/></linearGradient></defs>
                                                <circle class="track" cx="50" cy="50" r="42"/>
                                                <circle class="val" cx="50" cy="50" r="42"/>
                                            </svg>
                                            <div class="ring-label"><strong>87%</strong><small>Pass rate</small></div>
                                        </div>
                                        <p>Section 3 · Algorithms</p>
                                    </div>
                                </div>

                                <div class="card">
                                    <div class="card-title">Class climb <span>Last 10 quizzes</span></div>
                                    <div class="chart">
                                        <i style="--h:38%"></i><i style="--h:52%"></i><i style="--h:46%"></i><i style="--h:60%"></i><i style="--h:55%"></i>
                                        <i style="--h:68%"></i><i style="--h:63%"></i><i style="--h:74%"></i><i style="--h:80%"></i><i style="--h:92%"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="float float-a">
                        <span class="fi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.2 4.2L19 7"/></svg></span>
                        <div><b>Section B climbed +12%</b><small>32 answers auto-graded · just now</small></div>
                    </div>
                    <div class="float float-b">
                        <span class="fi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg></span>
                        <div><b>Exam mode on</b><small>Ends in <span class="timer" id="timer">24:59</span></small></div>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <footer>
        <div class="wrap">
            <span class="brand">
                <span class="brand-mark" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3v18M16 3v18"/><path d="M8 17h8M8 12h8M8 7h8" opacity=".9"/></svg>
                </span>
                <span class="brand-name">LADDER</span>
            </span>
            <span>Climb Higher. Achieve More.</span>
            <span>&copy; <?php echo date('Y'); ?> LADDER &middot; Developed by <a class="credit" href="https://salehrafi.vercel.app">Saleh Rafi</a></span>
        </div>
    </footer>

    <script>
        (function () {
            var nav = document.getElementById('nav');
            var onScroll = function () { nav.classList.toggle('scrolled', window.scrollY > 8); };
            window.addEventListener('scroll', onScroll, { passive: true });
            onScroll();

            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
            var el = document.getElementById('timer');
            var s = 24 * 60 + 59;
            setInterval(function () {
                s = s > 0 ? s - 1 : 24 * 60 + 59;
                el.textContent = Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
            }, 1000);
        })();
    </script>
</body>

</html>
