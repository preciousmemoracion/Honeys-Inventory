<?php
/*
 * Landing page with the sign-in form in a modal.
 * Redirects (timeout, logout, "please sign in") come here with ?login=1 and the modal opens by itself.
 */
require_once "auth.php";

date_default_timezone_set('Asia/Manila');

if (!function_exists('e')) {
    function e($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

$loggedIn = is_logged_in();

/*
 * The only account allowed to sign in. No database lookup is needed.
 * Username: admin
 * To change the password, make a new hash and paste it below:
 *     php -r "echo password_hash('NewPassword', PASSWORD_DEFAULT);"
 */
const ADMIN_USERNAME      = 'admin';
const ADMIN_PASSWORD_HASH = '$2b$10$MBxyWysxSBIoWoVaZyxjK.SWlKb4niAtkbxyJD3obJTCmyd7wbc5u';
const MAX_FAILED_ATTEMPTS = 5;
const LOCKOUT_SECONDS     = 60;

/* Only allow redirects to a local page like "history.php?receipt=3" */
$next = (string)($_GET['next'] ?? $_POST['next'] ?? '');
if (!preg_match('/^[A-Za-z0-9_]+\.php(\?[A-Za-z0-9_=&%.-]*)?$/', $next)) {
    $next = 'dashboard.php';
}

$error    = '';
$notice   = '';
$username = '';

if (($_GET['timeout'] ?? '') === '1') {
    $notice = 'You were signed out after 30 minutes of inactivity. Please sign in again.';
} elseif (($_GET['msg'] ?? '') === 'loggedout') {
    $notice = 'You have been signed out.';
}

$lockLeft = max(0, (int)($_SESSION['lock_until'] ?? 0) - time());

if (!$loggedIn && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } elseif ($lockLeft > 0) {
        $error = 'Too many wrong attempts. Try again in ' . $lockLeft . ' ' . ($lockLeft === 1 ? 'second' : 'seconds') . '.';
    } elseif ($username === '' || $password === '') {
        $error = 'Enter your username and password.';
    } else {
        try {
            // Always check the password so the response time is the same
            // whether or not the username was right.
            $passwordOk = password_verify($password, ADMIN_PASSWORD_HASH);
            $usernameOk = strcasecmp($username, ADMIN_USERNAME) === 0;

            if ($passwordOk && $usernameOk) {
                unset($_SESSION['failed_attempts'], $_SESSION['lock_until']);
                session_regenerate_id(true);
                $_SESSION['user_id']     = 1;
                $_SESSION['username']    = ADMIN_USERNAME;
                $_SESSION['full_name']   = 'Admin';
                $_SESSION['role']        = 'admin';
                $_SESSION['last_active'] = time();

                header('Location: ' . $next);
                exit;
            }

            $_SESSION['failed_attempts'] = (int)($_SESSION['failed_attempts'] ?? 0) + 1;
            if ($_SESSION['failed_attempts'] >= MAX_FAILED_ATTEMPTS) {
                $_SESSION['failed_attempts'] = 0;
                $_SESSION['lock_until'] = time() + LOCKOUT_SECONDS;
                $error = 'Too many wrong attempts. Wait a minute, then try again.';
            } else {
                $error = 'Incorrect username or password.';
            }
        } catch (Throwable $ex) {
            error_log('Login error: ' . $ex->getMessage());
            $error = "Sign in isn't available right now. Please try again shortly.";
        }
    }
}

$openModal     = !$loggedIn && ($error !== '' || $notice !== '' || isset($_GET['login']));
$focusPassword = ($error !== '' && $username !== '');
$describedBy   = ($error !== '' || $notice !== '') ? ' aria-describedby="formMsg"' : '';
$invalid       = ($error !== '') ? ' aria-invalid="true"' : '';
$signInHref    = 'index.php?login=1' . ($next !== 'dashboard.php' ? '&next=' . urlencode($next) : '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Honey's Dressed Chicken and Frozen Food | Inventory</title>
    <meta name="description" content="Inventory, sales and delivery tracking for Honey's Dressed Chicken and Frozen Food.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wdth,wght@12..96,75..100,600..800&family=Figtree:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="theme.css">
    <style>
        .wrap { width: min(1120px, 100% - 2.5rem); margin-inline: auto; }

        /* ---------- Top bar ---------- */
        .lp-nav { position: sticky; top: 0; z-index: 20; -webkit-backdrop-filter: blur(14px); backdrop-filter: blur(14px);
            background: linear-gradient(180deg, rgba(10, 14, 42, .85), rgba(10, 14, 42, .5)); border-bottom: 1px solid rgba(255, 255, 255, .06); }
        .lp-nav .wrap { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: .8rem 0; }
        .brand { display: flex; align-items: center; gap: .8rem; color: var(--text); text-decoration: none; }
        .brand-mark { display: grid; place-items: center; flex: none; width: 2.6rem; height: 2.6rem;
            border-radius: 50% 50% 50% 50% / 62% 62% 38% 38%; background: linear-gradient(135deg, var(--violet), var(--cyan)); color: #fff;
            font: 800 1.4rem var(--display); box-shadow: 0 8px 20px -8px rgba(124, 107, 255, .9), inset 0 1px 0 rgba(255, 255, 255, .35); }
        .brand strong { display: block; font: 700 1.25rem var(--display); line-height: 1.1; letter-spacing: -.01em; }
        .brand small { display: block; font-size: .78rem; color: var(--muted); }

        /* ---------- Hero ---------- */
        .hero { display: grid; grid-template-columns: minmax(0, 6fr) minmax(0, 5fr); gap: clamp(2rem, 5vw, 4.5rem); align-items: center;
            padding: clamp(3rem, 8vw, 6.5rem) 0; }
        .eyebrow { display: inline-block; margin: 0 0 1rem; padding: .3rem .8rem; border: 1px solid var(--line-strong); border-radius: 999px;
            background: var(--glass); color: var(--cyan-soft); font-size: .85rem; font-weight: 600; }
        .hero h1 { margin: 0 0 1.1rem; font: 700 clamp(2.5rem, 5.6vw, 4.4rem) var(--display); letter-spacing: -.03em; line-height: 1;
            font-variation-settings: "wdth" 82, "opsz" 72; }
        .hero h1 em { font-style: normal; background: linear-gradient(135deg, var(--cyan-soft), var(--violet)); -webkit-background-clip: text;
            background-clip: text; color: transparent; }
        .hero p.lead { margin: 0 0 2rem; max-width: 30em; color: var(--muted); font-size: 1.12rem; line-height: 1.6; }
        .cta { display: flex; flex-wrap: wrap; gap: .75rem; }
        .cta .btn { min-height: 3.1rem; padding-inline: 1.5rem; font-size: 1rem; }

        .rack { display: grid; gap: 1.4rem; padding: 1.5rem; border: 1px solid var(--line); border-radius: 20px;
            background: linear-gradient(145deg, rgba(255, 255, 255, .10), rgba(255, 255, 255, .035));
            box-shadow: 0 24px 60px -28px rgba(0, 0, 0, .8), inset 0 1px 0 rgba(255, 255, 255, .08); }
        .rack h2 { margin: 0; font-size: 1rem; color: var(--muted); font-weight: 600; letter-spacing: .02em; }
        .rack p { display: flex; justify-content: space-between; gap: 1rem; margin: 0 0 .5rem; font-size: .92rem; color: var(--muted); }
        .rack p b { color: var(--text); font-weight: 600; }
        .rack .low p span { color: var(--low); font-weight: 600; }
        .bar { position: relative; display: block; height: 12px; border-radius: 999px; background: rgba(255, 255, 255, .1); }
        .bar::before { content: ""; position: absolute; inset: 0 auto 0 0; width: var(--w); border-radius: 999px;
            background: linear-gradient(90deg, var(--cyan), var(--violet)); }
        .rack .low .bar::before { background: linear-gradient(90deg, #FFB085, var(--out)); }
        .bar i { position: absolute; z-index: 1; top: -5px; bottom: -5px; left: 33%; width: 2px; border-radius: 1px; background: rgba(255, 255, 255, .85); }

        /* ---------- Features ---------- */
        .section-head { margin: 0 0 1.75rem; }
        .section-head h2 { margin: 0 0 .4rem; font: 700 clamp(1.8rem, 3.4vw, 2.5rem) var(--display); letter-spacing: -.02em; }
        .section-head p { margin: 0; color: var(--muted); max-width: 36em; }
        .features { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1rem; padding-bottom: clamp(3rem, 7vw, 5rem); }
        .feature { padding: 1.4rem 1.25rem; border: 1px solid var(--line); border-radius: var(--radius);
            background: linear-gradient(145deg, rgba(255, 255, 255, .08), rgba(255, 255, 255, .03)); }
        .feature .ico { display: grid; place-items: center; width: 2.8rem; height: 2.8rem; margin-bottom: 1rem; border-radius: 12px;
            background: linear-gradient(135deg, rgba(124, 107, 255, .35), rgba(34, 211, 238, .18)); color: var(--cyan-soft); }
        .feature .ico svg { fill: currentColor; }
        .feature h3 { margin: 0 0 .4rem; font-size: 1.15rem; }
        .feature p { margin: 0; color: var(--muted); font-size: .96rem; line-height: 1.5; }

        .band { margin-bottom: clamp(3rem, 7vw, 5rem); padding: clamp(1.75rem, 4vw, 2.75rem); border: 1px solid var(--line); border-radius: 22px;
            display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 1.25rem;
            background: linear-gradient(160deg, rgba(124, 107, 255, .35), rgba(34, 211, 238, .08) 80%); }
        .band h2 { margin: 0 0 .3rem; font: 700 clamp(1.5rem, 3vw, 2rem) var(--display); letter-spacing: -.02em; }
        .band p { margin: 0; color: var(--muted); }

        .lp-foot { padding: 1.5rem 0 2.5rem; border-top: 1px solid var(--line); color: var(--muted); font-size: .9rem; }
        .lp-foot .wrap { display: flex; flex-wrap: wrap; justify-content: space-between; gap: .5rem 1.5rem; }

        /* ---------- Sign-in modal ---------- */
        dialog.signin { width: min(440px, calc(100% - 2rem)); max-height: calc(100dvh - 2rem); margin: auto; padding: 2.1rem 1.9rem 1.8rem;
            overflow-y: auto; color: var(--text); border: 1px solid var(--line-strong); border-radius: 22px;
            background: linear-gradient(145deg, rgba(34, 40, 104, .97), rgba(14, 18, 54, .98));
            box-shadow: 0 30px 80px -20px rgba(0, 0, 0, .85), inset 0 1px 0 rgba(255, 255, 255, .1); }
        dialog.signin::backdrop { background: rgba(4, 6, 24, .72); -webkit-backdrop-filter: blur(6px); backdrop-filter: blur(6px); }
        .signin .x { position: absolute; top: .75rem; right: .75rem; display: grid; place-items: center; width: 2.4rem; height: 2.4rem;
            border: 1px solid transparent; border-radius: 10px; background: transparent; color: var(--muted); cursor: pointer; }
        .signin .x:hover { background: rgba(255, 255, 255, .1); color: #fff; }
        .signin .x svg { fill: currentColor; }
        .signin h2 { margin: 0 0 .35rem; font: 700 2.2rem var(--display); letter-spacing: -.03em; line-height: 1.05;
            font-variation-settings: "wdth" 88, "opsz" 64; }
        .signin p.lead { margin: 0 0 1.5rem; color: var(--muted); }

        .msg { display: flex; align-items: flex-start; gap: .65rem; margin: 0 0 1.25rem; padding: .8rem .95rem; border-radius: 12px;
            font-size: .94rem; font-weight: 500; line-height: 1.4; }
        .msg svg { flex: none; width: 1.15rem; height: 1.15rem; margin-top: .08rem; }
        .msg.error  { background: var(--out-bg); color: var(--out-text); box-shadow: inset 0 0 0 1px rgba(255, 92, 112, .45); }
        .msg.notice { background: var(--ok-bg);  color: #9DF3E9;        box-shadow: inset 0 0 0 1px rgba(53, 224, 208, .38); }

        .signin .field { margin-bottom: 1.15rem; }
        .signin .field input { height: 3.1rem; font-size: 1.02rem; }
        .signin .field input[aria-invalid="true"] { border-color: var(--out); }
        .signin .field input[aria-invalid="true"]:focus { box-shadow: 0 0 0 4px rgba(255, 92, 112, .22); }
        .signin .field input:-webkit-autofill { -webkit-box-shadow: 0 0 0 40px #141A4A inset; -webkit-text-fill-color: var(--text); caret-color: var(--text); }
        .pw { position: relative; }
        .signin .field .pw input { padding-right: 4.75rem; }
        .pw button { position: absolute; top: 50%; right: .45rem; transform: translateY(-50%); min-width: 3.6rem; height: 2.2rem; padding: 0 .65rem;
            border: 0; border-radius: 8px; background: transparent; color: var(--cyan-soft); font: 600 .88rem var(--body); cursor: pointer; }
        .pw button:hover { background: rgba(255, 255, 255, .1); }
        .signin .hint { margin: .5rem 0 0; color: var(--low); font-size: .88rem; font-weight: 500; }
        .signin .hint[hidden] { display: none; }
        .submit { width: 100%; height: 3.2rem; margin-top: .5rem; border: 1px solid rgba(190, 182, 255, .45); border-radius: 12px;
            background: linear-gradient(135deg, var(--violet-a), var(--violet-b)); color: #fff; font: 700 1.02rem var(--body); cursor: pointer;
            box-shadow: 0 10px 26px -12px rgba(99, 88, 242, .95), inset 0 1px 0 rgba(255, 255, 255, .22); }
        .submit:hover { background: linear-gradient(135deg, #7468FF, #5649EA); }
        .submit:active { transform: translateY(1px); }
        .submit:disabled { opacity: .65; cursor: wait; transform: none; }
        .signin .foot { margin: 1.5rem 0 0; padding-top: 1rem; border-top: 1px solid var(--line); font-size: .88rem; color: var(--muted); }
        .signin .field input:focus-visible { outline: none; }

        @media (prefers-reduced-motion: no-preference) {
            .bar::before { animation: fill 1.2s cubic-bezier(.2, .7, .2, 1) both; animation-delay: var(--d, 0s); }
            .msg.error { animation: nudge .35s ease-out; }
            dialog.signin[open] { animation: pop .22s ease-out; }
            @keyframes fill  { from { width: 0; } }
            @keyframes nudge { 25% { transform: translateX(-5px); } 55% { transform: translateX(4px); } 80% { transform: translateX(-2px); } }
            @keyframes pop   { from { opacity: 0; transform: translateY(10px) scale(.98); } }
        }

        @media (max-width: 900px) {
            .hero { grid-template-columns: minmax(0, 1fr); }
            .features { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 560px) {
            .features { grid-template-columns: minmax(0, 1fr); }
            .brand small { display: none; }
            dialog.signin { padding: 1.8rem 1.25rem 1.5rem; }
        }
    </style>
</head>
<body>

<header class="lp-nav">
    <div class="wrap">
        <a class="brand" href="index.php">
            <span class="brand-mark" aria-hidden="true">H</span>
            <span>
                <strong>Honey's</strong>
                <small>Dressed Chicken and Frozen Food</small>
            </span>
        </a>
        <?php if ($loggedIn): ?>
            <a class="btn primary" href="dashboard.php">Open dashboard</a>
        <?php else: ?>
            <a class="btn primary" href="<?= e($signInHref) ?>" data-open-login>Sign in</a>
        <?php endif; ?>
    </div>
</header>

<main>
    <section class="wrap hero" aria-labelledby="hero-title">
        <div>
            <p class="eyebrow">Inventory system for Honey's</p>
            <h1 id="hero-title">Know what's in the freezer, <em>always.</em></h1>
            <p class="lead">Keep track of stock, ring up customers, and record supplier deliveries in one place, so nothing runs out unnoticed.</p>
            <div class="cta">
                <?php if ($loggedIn): ?>
                    <a class="btn primary" href="dashboard.php">Open dashboard</a>
                <?php else: ?>
                    <a class="btn primary" href="<?= e($signInHref) ?>" data-open-login>Sign in to continue</a>
                <?php endif; ?>
                <a class="btn" href="#features">See what it does</a>
            </div>
        </div>

        <div class="rack" aria-hidden="true">
            <h2>Stock levels at a glance</h2>
            <div>
                <p><b>Whole dressed chicken</b><span>84 kg</span></p>
                <span class="bar" style="--w: 84%; --d: .15s"><i></i></span>
            </div>
            <div>
                <p><b>Chicken wings</b><span>58 kg</span></p>
                <span class="bar" style="--w: 58%; --d: .3s"><i></i></span>
            </div>
            <div class="low">
                <p><b>Frozen hotdog</b><span>Low stock</span></p>
                <span class="bar" style="--w: 20%; --d: .45s"><i></i></span>
            </div>
        </div>
    </section>

    <section class="wrap" id="features" aria-labelledby="features-title">
        <div class="section-head">
            <h2 id="features-title">Everything the shop needs day to day</h2>
            <p>Simple tools for the counter and the stockroom.</p>
        </div>
        <div class="features">
            <article class="feature">
                <span class="ico" aria-hidden="true"><svg width="24" height="24" viewBox="0 0 24 24"><path d="M12 2l-5.5 9h11L12 2zm5.5 11c-2.49 0-4.5 2.01-4.5 4.5s2.01 4.5 4.5 4.5 4.5-2.01 4.5-4.5-2.01-4.5-4.5-4.5zM3 21.5h8v-8H3v8z"/></svg></span>
                <h3>Products and stock</h3>
                <p>See every item, its price and how much is left, with warnings when stock runs low.</p>
            </article>
            <article class="feature">
                <span class="ico" aria-hidden="true"><svg width="24" height="24" viewBox="0 0 24 24"><path d="M18 17H6v-2h12v2zm0-4H6v-2h12v2zm0-4H6V7h12v2zM3 22l1.5-1.5L6 22l1.5-1.5L9 22l1.5-1.5L12 22l1.5-1.5L15 22l1.5-1.5L18 22l1.5-1.5L21 22V2l-1.5 1.5L18 2l-1.5 1.5L15 2l-1.5 1.5L12 2l-1.5 1.5L9 2 7.5 3.5 6 2 4.5 3.5 3 2v20z"/></svg></span>
                <h3>Sales and receipts</h3>
                <p>Ring up a sale, print the receipt, and look up past sales any time.</p>
            </article>
            <article class="feature">
                <span class="ico" aria-hidden="true"><svg width="24" height="24" viewBox="0 0 24 24"><path d="M19 3H4.99c-1.11 0-1.98.9-1.98 2L3 19c0 1.1.88 2 1.99 2H19c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 12h-4c0 1.66-1.35 3-3 3s-3-1.34-3-3H4.99V5H19v10zm-3-5h-2V7h-4v3H8l4 4 4-4z"/></svg></span>
                <h3>Deliveries</h3>
                <p>Record what suppliers bring in and stock levels update right away.</p>
            </article>
            <article class="feature">
                <span class="ico" aria-hidden="true"><svg width="24" height="24" viewBox="0 0 24 24"><path d="M5 9.2h3V19H5V9.2zM10.6 5h2.8v14h-2.8V5zm5.6 8H19v6h-2.8v-6z"/></svg></span>
                <h3>Reports</h3>
                <p>Daily sales, best sellers and category totals, ready to print or export to CSV.</p>
            </article>
        </div>
    </section>

    <?php if (!$loggedIn): ?>
    <section class="wrap" aria-label="Sign in">
        <div class="band">
            <div>
                <h2>Ready to get started?</h2>
                <p>Only the admin account can sign in.</p>
            </div>
            <a class="btn primary" href="<?= e($signInHref) ?>" data-open-login>Sign in</a>
        </div>
    </section>
    <?php endif; ?>
</main>

<footer class="lp-foot">
    <div class="wrap">
        <span>&copy; <?= date('Y') ?> Honey's Dressed Chicken and Frozen Food</span>
        <span>Poblacion, Inabanga, Bohol</span>
    </div>
</footer>

<?php if (!$loggedIn): ?>
<dialog class="signin" id="loginModal" aria-labelledby="loginTitle">
    <button class="x" type="button" data-close-login aria-label="Close sign in">
        <svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><path d="M19 6.41 17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
    </button>

    <h2 id="loginTitle">Sign in</h2>
    <p class="lead">Only the admin account can sign in.</p>

    <?php if ($notice): ?>
        <p class="msg notice" role="status" id="formMsg">
            <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="10" cy="10" r="8"/><path d="M10 9v5M10 6.2v.1"/></svg>
            <span><?= e($notice) ?></span>
        </p>
    <?php endif; ?>
    <?php if ($error): ?>
        <p class="msg error" role="alert" id="formMsg">
            <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="10" cy="10" r="8"/><path d="M10 5.8v5M10 13.8v.1"/></svg>
            <span><?= e($error) ?></span>
        </p>
    <?php endif; ?>

    <form method="POST" action="index.php" id="loginForm" novalidate>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="next" value="<?= e($next) ?>">

        <div class="field">
            <label for="username">Username</label>
            <input id="username" name="username" value="<?= e($username) ?>" maxlength="50"
                   autocomplete="username" autocapitalize="none" spellcheck="false" required
                   <?= $focusPassword ? '' : 'autofocus' ?><?= $describedBy . $invalid ?>>
        </div>

        <div class="field">
            <label for="password">Password</label>
            <div class="pw">
                <input id="password" type="password" name="password"
                       autocomplete="current-password" required
                       <?= $focusPassword ? 'autofocus' : '' ?><?= $describedBy . $invalid ?>>
                <button type="button" id="togglePw" aria-controls="password" aria-pressed="false">Show</button>
            </div>
            <p class="hint" id="capsHint" role="status" hidden>Caps Lock is on.</p>
        </div>

        <button class="submit" type="submit" id="submitBtn">Sign in</button>
    </form>

    <p class="foot">Forgot your password? Ask the person who manages this system to reset it.</p>
</dialog>

<script>
(function () {
    var modal = document.getElementById('loginModal');
    var form = document.getElementById('loginForm');
    var pw = document.getElementById('password');
    var toggle = document.getElementById('togglePw');
    var btn = document.getElementById('submitBtn');
    var caps = document.getElementById('capsHint');
    if (!modal || typeof modal.showModal !== 'function') return;   // links still work: they reload with ?login=1

    function openModal() { if (!modal.open) modal.showModal(); }
    function closeModal() { modal.close(); }

    Array.prototype.forEach.call(document.querySelectorAll('[data-open-login]'), function (a) {
        a.addEventListener('click', function (e) { e.preventDefault(); openModal(); });
    });
    modal.querySelector('[data-close-login]').addEventListener('click', closeModal);

    // Click on the dimmed area (the dialog element itself) closes it.
    modal.addEventListener('mousedown', function (e) {
        if (e.target !== modal) return;
        var r = modal.getBoundingClientRect();
        if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) closeModal();
    });

    // Drop ?login=1 etc. from the address bar once the modal is closed.
    modal.addEventListener('close', function () {
        if (location.search && window.history.replaceState) history.replaceState(null, '', location.pathname);
    });

    toggle.addEventListener('click', function () {
        var show = pw.type === 'password';
        pw.type = show ? 'text' : 'password';
        toggle.textContent = show ? 'Hide' : 'Show';
        toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
    });

    function checkCaps(ev) { if (ev.getModifierState) caps.hidden = !ev.getModifierState('CapsLock'); }
    pw.addEventListener('keydown', checkCaps);
    pw.addEventListener('keyup', checkCaps);
    pw.addEventListener('blur', function () { caps.hidden = true; });

    form.addEventListener('submit', function () { btn.disabled = true; btn.textContent = 'Signing in\u2026'; });
    window.addEventListener('pageshow', function () { btn.disabled = false; btn.textContent = 'Sign in'; });

    <?php if ($openModal): ?>openModal();<?php endif; ?>
})();
</script>
<?php endif; ?>
</body>
</html>