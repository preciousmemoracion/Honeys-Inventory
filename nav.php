<?php
/*
 * Shared sidebar. Include it right after <body> on every page:
 *     <?php include "nav.php"; ?>
 * Pages should wrap their content in <main class="content"> so it sits beside the sidebar.
 */
require_once __DIR__ . "/auth.php";

$navPage = basename($_SERVER['SCRIPT_NAME']);
if ($navPage === 'edit_product.php') { $navPage = 'products.php'; }   // editing keeps Products highlighted

$navItems = [
    ['dashboard.php', 'Dashboard',
        'M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z'],
    ['products.php', 'Products',
        'M12 2l-5.5 9h11L12 2zm5.5 11c-2.49 0-4.5 2.01-4.5 4.5s2.01 4.5 4.5 4.5 4.5-2.01 4.5-4.5-2.01-4.5-4.5-4.5zM3 21.5h8v-8H3v8z'],
    ['sales.php', 'Sales and receipt',
        'M18 17H6v-2h12v2zm0-4H6v-2h12v2zm0-4H6V7h12v2zM3 22l1.5-1.5L6 22l1.5-1.5L9 22l1.5-1.5L12 22l1.5-1.5L15 22l1.5-1.5L18 22l1.5-1.5L21 22V2l-1.5 1.5L18 2l-1.5 1.5L15 2l-1.5 1.5L12 2l-1.5 1.5L9 2 7.5 3.5 6 2 4.5 3.5 3 2v20z'],
    ['history.php', 'Sales history',
        'M13 3a9 9 0 0 0-9 9H1l3.89 3.89.07.14L9 12H6c0-3.87 3.13-7 7-7s7 3.13 7 7-3.13 7-7 7c-1.93 0-3.68-.79-4.94-2.06l-1.42 1.42A8.954 8.954 0 0 0 13 21a9 9 0 0 0 0-18zm-1 5v5l4.28 2.54.72-1.21-3.5-2.08V8H12z'],
    ['stock_in.php', 'Stock in',
        'M19 3H4.99c-1.11 0-1.98.9-1.98 2L3 19c0 1.1.88 2 1.99 2H19c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 12h-4c0 1.66-1.35 3-3 3s-3-1.34-3-3H4.99V5H19v10zm-3-5h-2V7h-4v3H8l4 4 4-4z'],
    ['movements.php', 'Stock log',
        'M3 13h2v-2H3v2zm0 4h2v-2H3v2zm0-8h2V7H3v2zm4 4h14v-2H7v2zm0 4h14v-2H7v2zM7 7v2h14V7H7z'],
    ['reports.php', 'Reports',
        'M5 9.2h3V19H5V9.2zM10.6 5h2.8v14h-2.8V5zm5.6 8H19v6h-2.8v-6z'],
];

$navLow = (isset($stats) && is_array($stats)) ? (int)($stats['low'] ?? 0) : 0;

$navUser    = current_user_name();
$navInitial = function_exists('mb_substr') ? mb_strtoupper(mb_substr($navUser, 0, 1)) : strtoupper(substr($navUser, 0, 1));
if ($navInitial === '') { $navInitial = '?'; }
?>
<style>
    /* Sidebar: frosted glass panel */
    .sidebar {
        position: fixed; inset: 0 auto 0 0; z-index: 50; width: 264px;
        display: flex; flex-direction: column; gap: 1.75rem; overflow-y: auto;
        padding: 1.4rem .9rem 1.1rem; color: var(--text);
        font-family: var(--body);
        background: linear-gradient(180deg, rgba(255, 255, 255, .075), rgba(255, 255, 255, .025)), rgba(10, 14, 42, .72);
        -webkit-backdrop-filter: blur(18px); backdrop-filter: blur(18px);
        border-right: 1px solid var(--line);
    }
    .sidebar .brand { display: flex; align-items: center; gap: .8rem; padding: .25rem .5rem; color: var(--text); text-decoration: none; }
    .sidebar .brand-mark { flex: none; display: grid; place-items: center; width: 2.6rem; height: 2.6rem;
        border-radius: 50% 50% 50% 50% / 62% 62% 38% 38%;
        background: linear-gradient(135deg, var(--violet), var(--cyan)); color: #fff;
        box-shadow: 0 8px 20px -8px rgba(124, 107, 255, .9), inset 0 1px 0 rgba(255, 255, 255, .35);
        font: 800 1.4rem var(--display); }
    .sidebar .brand strong { display: block; font: 700 1.25rem var(--display); line-height: 1.1; letter-spacing: -.01em; }
    .sidebar .brand small { display: block; margin-top: .15rem; font-size: .78rem; line-height: 1.25; color: var(--muted); }
    .sidebar ul { margin: 0; padding: 0; list-style: none; display: grid; gap: .3rem; }
    .sidebar li a { position: relative; display: flex; align-items: center; gap: .8rem;
        min-height: 2.9rem; padding: .6rem .85rem; border: 1px solid transparent; border-radius: 12px; color: var(--muted);
        font-size: .98rem; font-weight: 500; text-decoration: none; }
    .sidebar li a svg { flex: none; fill: currentColor; opacity: .9; }
    .sidebar li a:hover { background: rgba(255, 255, 255, .07); color: var(--text); }
    .sidebar li a.active { background: linear-gradient(135deg, rgba(124, 107, 255, .30), rgba(255, 255, 255, .08));
        border-color: rgba(190, 182, 255, .35); color: #fff; font-weight: 600; }
    .sidebar li a.active svg { color: var(--cyan-soft); opacity: 1; }
    .sidebar .pill { margin-left: auto; min-width: 1.5rem; padding: .05rem .5rem; border-radius: 999px;
        background: var(--out); color: #fff; font-size: .8rem; font-weight: 700; text-align: center; }

    .sidebar .side-foot { margin-top: auto; padding-top: 1rem; border-top: 1px solid var(--line); }
    .sidebar .who { display: flex; align-items: center; gap: .7rem; margin: 0 0 .8rem; padding: 0 .25rem; }
    .sidebar .avatar, .topbar .avatar { flex: none; display: grid; place-items: center; width: 2.25rem; height: 2.25rem; border-radius: 50%;
        background: linear-gradient(135deg, var(--violet), var(--cyan)); color: #fff; font: 700 1rem var(--display); }
    .sidebar .who strong { display: block; font-size: .95rem; line-height: 1.2; }
    .sidebar .who small { display: block; font-size: .8rem; color: var(--muted); }
    .sidebar .side-foot button { width: 100%; min-height: 2.6rem; border: 1px solid var(--line-strong);
        border-radius: 12px; background: rgba(255, 255, 255, .05); color: var(--text); font: 600 .95rem var(--body); cursor: pointer; }
    .sidebar .side-foot button:hover { background: rgba(255, 255, 255, .13); border-color: rgba(255, 255, 255, .4); }
    .sidebar a:focus-visible, .sidebar button:focus-visible, .nav-toggle:focus-visible,
    .topbar a:focus-visible, .topbar input:focus-visible { outline: 3px solid var(--cyan); outline-offset: 2px; }

    /* Top bar: search and low-stock bell, like the reference */
    .topbar { position: sticky; top: 0; z-index: 30; margin-left: 264px; display: flex; align-items: center; justify-content: flex-end;
        gap: .75rem; padding: .9rem 2.25rem; font-family: var(--body);
        background: linear-gradient(180deg, rgba(10, 14, 42, .88), rgba(10, 14, 42, .55));
        -webkit-backdrop-filter: blur(14px); backdrop-filter: blur(14px); border-bottom: 1px solid rgba(255, 255, 255, .06); }
    .topbar form { position: relative; flex: 0 1 340px; margin: 0; }
    .topbar input[type=search] { width: 100%; height: 2.7rem; padding: 0 1rem 0 2.6rem; border: 1px solid var(--line-strong);
        border-radius: 12px; background: rgba(255, 255, 255, .07); color: var(--text); font: 400 .95rem var(--body); }
    .topbar input[type=search]:focus { outline: none; border-color: var(--cyan); box-shadow: 0 0 0 4px rgba(34, 211, 238, .18); }
    .topbar form svg { position: absolute; left: .85rem; top: 50%; transform: translateY(-50%); fill: var(--muted); pointer-events: none; }
    .topbar .bell { position: relative; display: grid; place-items: center; width: 2.7rem; height: 2.7rem; border: 1px solid var(--line-strong);
        border-radius: 12px; background: rgba(255, 255, 255, .07); color: var(--text); }
    .topbar .bell:hover { background: rgba(255, 255, 255, .14); }
    .topbar .bell svg { fill: currentColor; }
    .topbar .bell .dot { position: absolute; top: -.4rem; right: -.4rem; display: grid; place-items: center; min-width: 1.25rem; height: 1.25rem;
        padding: 0 .3rem; border-radius: 999px; background: var(--out); color: #fff; font-size: .72rem; font-weight: 700; }
    .topbar .avatar { width: 2.7rem; height: 2.7rem; }

    body main.content { margin-left: 264px; padding: 1.25rem 2.25rem 3rem; }

    .nav-toggle, .nav-scrim { display: none; }

    @media (max-width: 900px) {
        .sidebar { transform: translateX(-100%); box-shadow: 8px 0 24px rgba(0, 0, 0, .45); visibility: hidden; background: rgba(12, 16, 48, .96); }
        .sidebar.open { transform: none; visibility: visible; }
        .nav-toggle { position: fixed; top: .75rem; left: .75rem; z-index: 40; display: inline-flex;
            align-items: center; gap: .5rem; min-height: 2.7rem; padding: .55rem .95rem; border: 1px solid var(--line-strong); border-radius: 12px;
            background: rgba(20, 26, 74, .9); color: var(--text); box-shadow: 0 4px 14px rgba(0, 0, 0, .4);
            font: 600 .95rem var(--body); cursor: pointer; }
        .nav-toggle svg { fill: currentColor; }
        .nav-scrim.open { display: block; position: fixed; inset: 0; z-index: 45; background: rgba(4, 6, 24, .65); }
        .topbar { margin-left: 0; padding: .75rem 1rem .75rem 6.5rem; }
        .topbar form { flex: 1 1 auto; }
        .topbar .avatar { display: none; }
        body main.content { margin-left: 0; padding: 1rem 1rem 2rem; }
    }
    @media (max-width: 900px) and (prefers-reduced-motion: no-preference) {
        .sidebar { transition: transform .22s ease, visibility 0s linear .22s; }
        .sidebar.open { transition: transform .22s ease; }
    }
    @media print {
        .sidebar, .nav-toggle, .nav-scrim, .topbar { display: none !important; }
        body main.content { margin-left: 0; }
    }
</style>

<button class="nav-toggle" id="navToggle" type="button" aria-controls="sidebar" aria-expanded="false">
    <svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z"/></svg>
    Menu
</button>
<div class="nav-scrim" id="navScrim"></div>

<aside class="sidebar" id="sidebar" aria-label="Main navigation">
    <a class="brand" href="dashboard.php">
        <span class="brand-mark" aria-hidden="true">H</span>
        <span>
            <strong>Honey's</strong>
            <small>Dressed Chicken and Frozen Food</small>
        </span>
    </a>

    <nav aria-label="Pages">
        <ul>
            <?php foreach ($navItems as [$href, $label, $icon]):
                $active = ($navPage === $href);
            ?>
            <li>
                <a href="<?= htmlspecialchars($href) ?>"
                   class="<?= $active ? 'active' : '' ?>"
                   <?= $active ? 'aria-current="page"' : '' ?>>
                    <svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true"><path d="<?= $icon ?>"/></svg>
                    <span><?= htmlspecialchars($label) ?></span>
                    <?php if ($href === 'stock_in.php' && $navLow > 0): ?>
                        <span class="pill" title="Products low on stock"><?= $navLow ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
    </nav>

    <form class="side-foot" method="POST" action="logout.php">
        <div class="who">
            <span class="avatar" aria-hidden="true"><?= htmlspecialchars($navInitial) ?></span>
            <span>
                <strong><?= htmlspecialchars($navUser) ?></strong>
                <small>Signed in</small>
            </span>
        </div>
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
        <button type="submit">Sign out</button>
    </form>
</aside>

<header class="topbar no-print">
    <form method="GET" action="products.php" role="search">
        <svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><path d="M15.5 14h-.79l-.28-.27A6.47 6.47 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
        <label class="sr" for="globalSearch">Search products</label>
        <input id="globalSearch" type="search" name="q" data-global-search placeholder="Search products (press /)" autocomplete="off">
    </form>
    <a class="bell" href="products.php?status=low"
       aria-label="<?= $navLow > 0 ? $navLow . ' products low on stock' : 'No low-stock products' ?>"
       title="<?= $navLow > 0 ? $navLow . ' products low on stock' : 'Stock levels are healthy' ?>">
        <svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.89 2 2 2zm6-6v-5c0-3.07-1.64-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/></svg>
        <?php if ($navLow > 0): ?><span class="dot" aria-hidden="true"><?= $navLow ?></span><?php endif; ?>
    </a>
    <span class="avatar" aria-hidden="true"><?= htmlspecialchars($navInitial) ?></span>
</header>

<script>
(function () {
    var bar = document.getElementById('sidebar');
    var btn = document.getElementById('navToggle');
    var scrim = document.getElementById('navScrim');
    function setOpen(open) {
        bar.classList.toggle('open', open);
        scrim.classList.toggle('open', open);
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    btn.addEventListener('click', function () { setOpen(!bar.classList.contains('open')); });
    scrim.addEventListener('click', function () { setOpen(false); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setOpen(false); });
})();
</script>