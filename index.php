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

/* Shown in the Contact section. Leave a value empty to hide it. */
const CONTACT_PHONE = '';   // e.g. '0912 345 6789'
const CONTACT_EMAIL = '';   // e.g. 'honeys@example.com'
const CONTACT_HOURS = '';   // e.g. 'Mon to Sat, 7:00 AM to 6:00 PM'
const SHOP_ADDRESS  = 'Poblacion, Inabanga, Bohol';

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
    <meta name="theme-color" content="#0A0E2A">
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <script>document.documentElement.classList.add('js');</script>
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

        /* ---------- Enhancements: nav, demo, reveal, contact ---------- */
        html { scroll-padding-top: 5rem; }
        section[id] { scroll-margin-top: 4.5rem; }
        .lp-nav { transition: box-shadow .2s, background .2s; }
        .lp-nav.scrolled { box-shadow: 0 10px 30px -18px rgba(0, 0, 0, .8); background: rgba(10, 14, 42, .92); }
        .nav-links { display: flex; align-items: center; gap: .25rem; margin: 0 auto 0 1.5rem; padding: 0; list-style: none; }
        .nav-links a { position: relative; display: block; padding: .55rem .95rem; border-radius: 10px; color: var(--muted);
            font-weight: 600; font-size: .98rem; text-decoration: none; }
        .nav-links a:hover { color: var(--text); background: rgba(255, 255, 255, .07); }
        .nav-links a.active { color: #fff; }
        .nav-links a.active::after { content: ""; position: absolute; left: .95rem; right: .95rem; bottom: .2rem; height: 2px; border-radius: 2px;
            background: linear-gradient(90deg, var(--cyan), var(--violet)); }
        .nav-right { display: flex; align-items: center; gap: .6rem; }
        .menu-toggle { display: none; place-items: center; width: 2.7rem; height: 2.7rem; border: 1px solid var(--line-strong); border-radius: 12px;
            background: rgba(255, 255, 255, .07); color: var(--text); cursor: pointer; }
        .menu-toggle svg { fill: currentColor; }
        .menu-toggle .close { display: none; }
        .menu-toggle[aria-expanded="true"] .open { display: none; }
        .menu-toggle[aria-expanded="true"] .close { display: block; }

        .hero .blob { position: absolute; z-index: -1; width: 420px; height: 420px; right: -120px; top: -40px; border-radius: 50%;
            background: radial-gradient(circle, rgba(124, 107, 255, .35), transparent 65%); filter: blur(10px); pointer-events: none; }
        .hero { position: relative; }
        .hero .meta { display: flex; flex-wrap: wrap; gap: .5rem 1.25rem; margin: 1.75rem 0 0; padding: 0; list-style: none; color: var(--muted); font-size: .92rem; }
        .hero .meta li::before { content: "\2713"; margin-right: .45rem; color: var(--ok); font-weight: 700; }

        .demo-head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
        .demo-head button { border: 1px solid var(--line-strong); border-radius: 8px; background: transparent; color: var(--cyan-soft);
            font: 600 .82rem var(--body); padding: .3rem .65rem; cursor: pointer; }
        .demo-head button:hover { background: rgba(255, 255, 255, .1); }
        .demo .tag { padding: .1rem .55rem; border-radius: 999px; background: var(--ok-bg); color: #9DF3E9; font-size: .78rem; font-weight: 600; }
        .demo .low .tag { background: var(--low-bg); color: var(--low); }
        .demo .out .tag { background: var(--out-bg); color: var(--out-text); }
        .demo .item p span { display: inline-flex; align-items: center; gap: .55rem; }
        .demo .bar::before { width: var(--w); transition: width .35s cubic-bezier(.2, .7, .2, 1); animation-fill-mode: backwards; }
        .demo .bar i { left: var(--t, 33%); }
        .demo .out .bar::before { background: linear-gradient(90deg, #FFB085, var(--out)); }
        .demo .ctl { display: flex; gap: .5rem; margin-top: .75rem; }
        .demo .ctl button { flex: 1; min-height: 2.2rem; border: 1px solid var(--line-strong); border-radius: 10px; background: rgba(255, 255, 255, .06);
            color: var(--text); font: 600 .85rem var(--body); cursor: pointer; transition: background .15s, transform .1s; }
        .demo .ctl button:hover { background: rgba(255, 255, 255, .14); }
        .demo .ctl button:active { transform: scale(.97); }
        .demo .ctl button:disabled { opacity: .45; cursor: not-allowed; transform: none; }
        .demo em { font-style: normal; }
        .demo-note { margin: 0; color: var(--muted); font-size: .82rem; }

        .feature { position: relative; overflow: hidden; transition: transform .2s, border-color .2s; }
        .feature::before { content: ""; position: absolute; inset: 0; pointer-events: none; opacity: 0; transition: opacity .25s;
            background: radial-gradient(260px circle at var(--mx, 50%) var(--my, 0%), rgba(124, 107, 255, .28), transparent 70%); }
        .feature:hover { transform: translateY(-4px); border-color: rgba(190, 182, 255, .4); }
        .feature:hover::before { opacity: 1; }
        .feature > * { position: relative; }

        .steps { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1rem; margin: 0 0 clamp(3rem, 7vw, 5rem); padding: 0; list-style: none; counter-reset: s; }
        .steps li { counter-increment: s; position: relative; padding: 1.25rem 1.25rem 1.25rem 4.25rem; border: 1px dashed var(--line-strong); border-radius: var(--radius); }
        .steps li::before { content: counter(s); position: absolute; left: 1.1rem; top: 1.15rem; display: grid; place-items: center; width: 2.3rem; height: 2.3rem;
            border-radius: 50%; background: linear-gradient(135deg, var(--violet), var(--cyan)); color: #fff; font: 700 1.1rem var(--display); }
        .steps b { display: block; margin-bottom: .2rem; font: 700 1.05rem var(--display); }
        .steps span { color: var(--muted); font-size: .94rem; }

        .contact { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem; padding-bottom: clamp(3rem, 7vw, 5rem); }
        .ccard { display: flex; flex-direction: column; align-items: flex-start; gap: .35rem; padding: 1.4rem 1.25rem; border: 1px solid var(--line); border-radius: var(--radius);
            background: linear-gradient(145deg, rgba(255, 255, 255, .08), rgba(255, 255, 255, .03)); }
        .ccard h3 { margin: 0; font-size: 1.1rem; }
        .ccard p { margin: 0; color: var(--muted); }
        .ccard a:not(.btn) { color: var(--text); font-weight: 600; word-break: break-word; }
        .ccard .btn { margin-top: auto; }
        .ccard .ico { display: grid; place-items: center; width: 2.6rem; height: 2.6rem; margin-bottom: .5rem; border-radius: 12px;
            background: linear-gradient(135deg, rgba(124, 107, 255, .35), rgba(34, 211, 238, .18)); color: var(--cyan-soft); }
        .ccard .ico svg { fill: currentColor; }

        .lp-foot .links { display: flex; gap: 1.1rem; }
        .lp-foot .links a { color: var(--muted); text-decoration: none; }
        .lp-foot .links a:hover { color: var(--text); text-decoration: underline; }

        .to-top { position: fixed; right: 1.1rem; bottom: 1.1rem; z-index: 15; display: grid; place-items: center; width: 2.9rem; height: 2.9rem;
            border: 1px solid var(--line-strong); border-radius: 50%; background: rgba(20, 26, 74, .92); color: var(--text); cursor: pointer;
            box-shadow: 0 8px 22px rgba(0, 0, 0, .45); opacity: 0; transform: translateY(10px); pointer-events: none; transition: opacity .2s, transform .2s; }
        .to-top.show { opacity: 1; transform: none; pointer-events: auto; }
        .to-top svg { fill: currentColor; }

        .js .reveal { opacity: 0; transform: translateY(20px); transition: opacity .6s ease, transform .6s ease; transition-delay: var(--rd, 0s); }
        .js .reveal.in { opacity: 1; transform: none; }
        @media (prefers-reduced-motion: reduce) {
            .js .reveal { opacity: 1; transform: none; transition: none; }
            .feature, .feature:hover { transform: none; }
        }
        @media (prefers-reduced-motion: no-preference) { html { scroll-behavior: smooth; } }

        @media (max-width: 820px) {
            .menu-toggle { display: grid; }
            .nav-links { position: absolute; left: 0; right: 0; top: 100%; flex-direction: column; align-items: stretch; gap: .2rem; margin: 0;
                padding: .75rem 1.25rem 1rem; background: rgba(12, 16, 48, .97); -webkit-backdrop-filter: blur(14px); backdrop-filter: blur(14px);
                border-bottom: 1px solid var(--line); box-shadow: 0 18px 30px -20px rgba(0, 0, 0, .8);
                visibility: hidden; opacity: 0; transform: translateY(-8px); transition: opacity .2s, transform .2s, visibility 0s linear .2s; }
            .nav-links.open { visibility: visible; opacity: 1; transform: none; transition: opacity .2s, transform .2s; }
            .nav-links a { padding: .85rem 1rem; font-size: 1.05rem; }
            .nav-links a.active::after { left: 1rem; right: auto; width: 2rem; bottom: .45rem; }
            .nav-right { margin-left: auto; }
            .steps { grid-template-columns: minmax(0, 1fr); }
        }
        @media (max-width: 430px) { .nav-right .btn { padding-inline: .9rem; } }
    </style>
</head>
<body>

<header class="lp-nav" id="top">
    <div class="wrap">
        <a class="brand" href="#home">
            <span class="brand-mark" aria-hidden="true">H</span>
            <span>
                <strong>Honey's</strong>
                <small>Dressed Chicken and Frozen Food</small>
            </span>
        </a>

        <ul class="nav-links" id="navLinks">
            <li><a href="#home" data-spy="home">Home</a></li>
            <li><a href="#features" data-spy="features">Features</a></li>
            <li><a href="#contact" data-spy="contact">Contact</a></li>
        </ul>

        <div class="nav-right">
            <?php if ($loggedIn): ?>
                <a class="btn primary" href="dashboard.php">Open dashboard</a>
            <?php else: ?>
                <a class="btn primary" href="<?= e($signInHref) ?>" data-open-login>Sign in</a>
            <?php endif; ?>
            <button class="menu-toggle" id="menuToggle" type="button" aria-controls="navLinks" aria-expanded="false" aria-label="Menu">
                <svg class="open" width="22" height="22" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z"/></svg>
                <svg class="close" width="22" height="22" viewBox="0 0 24 24" aria-hidden="true"><path d="M19 6.41 17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
            </button>
        </div>
    </div>
</header>

<main>
    <section class="wrap hero" id="home" aria-labelledby="hero-title">
        <span class="blob" aria-hidden="true"></span>
        <div class="reveal">
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
            <ul class="meta">
                <li>Low-stock alerts</li>
                <li>Printable receipts</li>
                <li>CSV exports</li>
            </ul>
        </div>

        <div class="rack demo reveal" id="demo" style="--rd: .12s" aria-label="Interactive stock demo">
            <div class="demo-head">
                <h2>Try it: live stock demo</h2>
                <button type="button" id="demoReset">Reset</button>
            </div>

            <div class="item" data-name="Whole dressed chicken" data-qty="84" data-start="84" data-low="25" data-max="100" data-unit="kg">
                <p><b>Whole dressed chicken</b><span><em class="val"></em><em class="tag"></em></span></p>
                <span class="bar"><i></i></span>
                <div class="ctl"><button type="button" data-act="sell">Sell 10</button><button type="button" data-act="recv">Receive 10</button></div>
            </div>
            <div class="item" data-name="Chicken wings" data-qty="58" data-start="58" data-low="20" data-max="100" data-unit="kg">
                <p><b>Chicken wings</b><span><em class="val"></em><em class="tag"></em></span></p>
                <span class="bar"><i></i></span>
                <div class="ctl"><button type="button" data-act="sell">Sell 10</button><button type="button" data-act="recv">Receive 10</button></div>
            </div>
            <div class="item" data-name="Frozen hotdog" data-qty="20" data-start="20" data-low="20" data-max="100" data-unit="packs">
                <p><b>Frozen hotdog</b><span><em class="val"></em><em class="tag"></em></span></p>
                <span class="bar"><i></i></span>
                <div class="ctl"><button type="button" data-act="sell">Sell 10</button><button type="button" data-act="recv">Receive 10</button></div>
            </div>

            <p class="demo-note" id="demoMsg" role="status" aria-live="polite">Sample numbers only. The white tick marks where low stock starts.</p>
        </div>
    </section>

    <section class="wrap" id="features" aria-labelledby="features-title">
        <div class="section-head reveal">
            <h2 id="features-title">Everything the shop needs day to day</h2>
            <p>Simple tools for the counter and the stockroom.</p>
        </div>
        <div class="features">
            <article class="feature reveal">
                <span class="ico" aria-hidden="true"><svg width="24" height="24" viewBox="0 0 24 24"><path d="M12 2l-5.5 9h11L12 2zm5.5 11c-2.49 0-4.5 2.01-4.5 4.5s2.01 4.5 4.5 4.5 4.5-2.01 4.5-4.5-2.01-4.5-4.5-4.5zM3 21.5h8v-8H3v8z"/></svg></span>
                <h3>Products and stock</h3>
                <p>See every item, its price and how much is left, with warnings when stock runs low.</p>
            </article>
            <article class="feature reveal" style="--rd: .08s">
                <span class="ico" aria-hidden="true"><svg width="24" height="24" viewBox="0 0 24 24"><path d="M18 17H6v-2h12v2zm0-4H6v-2h12v2zm0-4H6V7h12v2zM3 22l1.5-1.5L6 22l1.5-1.5L9 22l1.5-1.5L12 22l1.5-1.5L15 22l1.5-1.5L18 22l1.5-1.5L21 22V2l-1.5 1.5L18 2l-1.5 1.5L15 2l-1.5 1.5L12 2l-1.5 1.5L9 2 7.5 3.5 6 2 4.5 3.5 3 2v20z"/></svg></span>
                <h3>Sales and receipts</h3>
                <p>Ring up a sale, print the receipt, and look up past sales any time.</p>
            </article>
            <article class="feature reveal" style="--rd: .16s">
                <span class="ico" aria-hidden="true"><svg width="24" height="24" viewBox="0 0 24 24"><path d="M19 3H4.99c-1.11 0-1.98.9-1.98 2L3 19c0 1.1.88 2 1.99 2H19c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 12h-4c0 1.66-1.35 3-3 3s-3-1.34-3-3H4.99V5H19v10zm-3-5h-2V7h-4v3H8l4 4 4-4z"/></svg></span>
                <h3>Deliveries</h3>
                <p>Record what suppliers bring in and stock levels update right away.</p>
            </article>
            <article class="feature reveal" style="--rd: .24s">
                <span class="ico" aria-hidden="true"><svg width="24" height="24" viewBox="0 0 24 24"><path d="M5 9.2h3V19H5V9.2zM10.6 5h2.8v14h-2.8V5zm5.6 8H19v6h-2.8v-6z"/></svg></span>
                <h3>Reports</h3>
                <p>Daily sales, best sellers and category totals, ready to print or export to CSV.</p>
            </article>
        </div>

        <ol class="steps">
            <li class="reveal"><b>Add your products</b><span>Set prices, units and when to be warned about low stock.</span></li>
            <li class="reveal" style="--rd: .1s"><b>Sell and receive</b><span>Sales take stock out and deliveries put it back, automatically.</span></li>
            <li class="reveal" style="--rd: .2s"><b>Check the numbers</b><span>Open reports to see what sells best and what to reorder.</span></li>
        </ol>
    </section>

    <?php if (!$loggedIn): ?>
    <section class="wrap" aria-label="Sign in">
        <div class="band reveal">
            <div>
                <h2>Ready to get started?</h2>
                <p>Only the admin account can sign in.</p>
            </div>
            <a class="btn primary" href="<?= e($signInHref) ?>" data-open-login>Sign in</a>
        </div>
    </section>
    <?php endif; ?>

    <section class="wrap" id="contact" aria-labelledby="contact-title">
        <div class="section-head reveal">
            <h2 id="contact-title">Contact and location</h2>
            <p>Drop by the shop or reach us using the details below.</p>
        </div>
        <div class="contact">
            <article class="ccard reveal">
                <span class="ico" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5a2.5 2.5 0 0 1 0-5 2.5 2.5 0 0 1 0 5z"/></svg></span>
                <h3>Visit us</h3>
                <p>Honey's Dressed Chicken and Frozen Food<br><?= e(SHOP_ADDRESS) ?></p>
                <a class="btn" href="https://www.google.com/maps/search/?api=1&amp;query=<?= e(urlencode("Honey's Dressed Chicken and Frozen Food, " . SHOP_ADDRESS)) ?>" target="_blank" rel="noopener">Get directions</a>
            </article>

            <?php if (CONTACT_PHONE !== ''): ?>
            <article class="ccard reveal" style="--rd: .08s">
                <span class="ico" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24"><path d="M6.62 10.79a15.05 15.05 0 0 0 6.59 6.59l2.2-2.2a1 1 0 0 1 1.02-.24c1.12.37 2.33.57 3.57.57a1 1 0 0 1 1 1V20a1 1 0 0 1-1 1A17 17 0 0 1 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1c0 1.25.2 2.45.57 3.57a1 1 0 0 1-.25 1.02l-2.2 2.2z"/></svg></span>
                <h3>Call or text</h3>
                <p><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', CONTACT_PHONE)) ?>"><?= e(CONTACT_PHONE) ?></a></p>
            </article>
            <?php endif; ?>

            <?php if (CONTACT_EMAIL !== ''): ?>
            <article class="ccard reveal" style="--rd: .16s">
                <span class="ico" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24"><path d="M20 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2zm0 4-8 5-8-5V6l8 5 8-5v2z"/></svg></span>
                <h3>Email</h3>
                <p><a href="mailto:<?= e(CONTACT_EMAIL) ?>"><?= e(CONTACT_EMAIL) ?></a></p>
            </article>
            <?php endif; ?>

            <?php if (CONTACT_HOURS !== ''): ?>
            <article class="ccard reveal" style="--rd: .24s">
                <span class="ico" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm4.2 14.2L11 13V7h1.5v5.2l4.5 2.7-.8 1.3z"/></svg></span>
                <h3>Opening hours</h3>
                <p><?= e(CONTACT_HOURS) ?></p>
            </article>
            <?php endif; ?>

            <article class="ccard reveal" style="--rd: .32s">
                <span class="ico" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24"><path d="M12 1 3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 10.99h7c-.53 4.12-3.28 7.79-7 8.94V12H5V6.3l7-3.11v8.8z"/></svg></span>
                <h3>Staff access</h3>
                <p>Shop staff can sign in to manage stock, sales and reports.</p>
                <?php if ($loggedIn): ?>
                    <a class="btn primary" href="dashboard.php">Open dashboard</a>
                <?php else: ?>
                    <a class="btn primary" href="<?= e($signInHref) ?>" data-open-login>Sign in</a>
                <?php endif; ?>
            </article>
        </div>
    </section>
</main>

<footer class="lp-foot">
    <div class="wrap">
        <span>&copy; <?= date('Y') ?> Honey's Dressed Chicken and Frozen Food</span>
        <nav class="links" aria-label="Footer">
            <a href="#home">Home</a>
            <a href="#features">Features</a>
            <a href="#contact">Contact</a>
        </nav>
        <span><?= e(SHOP_ADDRESS) ?></span>
    </div>
</footer>

<button class="to-top" id="toTop" type="button" aria-label="Back to top">
    <svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true"><path d="M7.41 15.41 12 10.83l4.59 4.58L18 14l-6-6-6 6z"/></svg>
</button>

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
<script>
(function () {
    var nav = document.querySelector('.lp-nav');
    var links = document.getElementById('navLinks');
    var toggle = document.getElementById('menuToggle');
    var toTop = document.getElementById('toTop');

    /* Mobile menu */
    function setMenu(open) {
        links.classList.toggle('open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    toggle.addEventListener('click', function () { setMenu(!links.classList.contains('open')); });
    links.addEventListener('click', function (e) { if (e.target.closest('a')) setMenu(false); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setMenu(false); });
    document.addEventListener('click', function (e) { if (!nav.contains(e.target)) setMenu(false); });
    window.addEventListener('resize', function () { if (window.innerWidth > 820) setMenu(false); });

    /* Header shadow and back-to-top */
    function onScroll() {
        nav.classList.toggle('scrolled', window.scrollY > 8);
        toTop.classList.toggle('show', window.scrollY > 600);
    }
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
    toTop.addEventListener('click', function () { window.scrollTo({ top: 0 }); });

    /* Highlight the nav link for the section in view */
    var spy = {};
    Array.prototype.forEach.call(document.querySelectorAll('[data-spy]'), function (a) { spy[a.getAttribute('data-spy')] = a; });
    function markActive() {
        var y = window.scrollY + window.innerHeight * 0.35, current = 'home';
        Object.keys(spy).forEach(function (id) {
            var el = document.getElementById(id);
            if (el && el.getBoundingClientRect().top + window.scrollY <= y) current = id;
        });
        if (window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 4) current = 'contact';
        Object.keys(spy).forEach(function (id) {
            var on = id === current;
            spy[id].classList.toggle('active', on);
            if (on) spy[id].setAttribute('aria-current', 'true'); else spy[id].removeAttribute('aria-current');
        });
    }
    window.addEventListener('scroll', markActive, { passive: true });
    window.addEventListener('resize', markActive);
    markActive();

    /* Reveal on scroll */
    var items = document.querySelectorAll('.reveal');
    if ('IntersectionObserver' in window) {
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (en) { if (en.isIntersecting) { en.target.classList.add('in'); io.unobserve(en.target); } });
        }, { threshold: 0.12 });
        Array.prototype.forEach.call(items, function (el) { io.observe(el); });
    } else {
        Array.prototype.forEach.call(items, function (el) { el.classList.add('in'); });
    }

    /* Feature cards: glow follows the pointer */
    Array.prototype.forEach.call(document.querySelectorAll('.feature'), function (card) {
        card.addEventListener('pointermove', function (e) {
            var r = card.getBoundingClientRect();
            card.style.setProperty('--mx', (e.clientX - r.left) + 'px');
            card.style.setProperty('--my', (e.clientY - r.top) + 'px');
        });
    });

    /* Stock demo */
    var demo = document.getElementById('demo');
    var msg = document.getElementById('demoMsg');
    var STEP = 10;
    function paint(item) {
        var q = +item.dataset.qty, low = +item.dataset.low, max = +item.dataset.max;
        var state = q <= 0 ? 'out' : (q <= low ? 'low' : 'ok');
        item.classList.remove('low', 'out');
        if (state !== 'ok') item.classList.add(state);
        item.querySelector('.val').textContent = q + ' ' + item.dataset.unit;
        item.querySelector('.tag').textContent = state === 'out' ? 'Out of stock' : (state === 'low' ? 'Low stock' : 'Available');
        var bar = item.querySelector('.bar');
        bar.style.setProperty('--w', Math.min(100, q / max * 100) + '%');
        bar.style.setProperty('--t', (low / max * 100) + '%');
        item.querySelector('[data-act="sell"]').disabled = q <= 0;
        item.querySelector('[data-act="recv"]').disabled = q >= max;
    }
    var rows = Array.prototype.slice.call(demo.querySelectorAll('.item'));
    rows.forEach(paint);
    demo.addEventListener('click', function (e) {
        var b = e.target.closest('button[data-act]');
        if (!b) return;
        var item = b.closest('.item'), q = +item.dataset.qty, max = +item.dataset.max;
        var wasLow = q <= +item.dataset.low;
        q = b.dataset.act === 'sell' ? Math.max(0, q - STEP) : Math.min(max, q + STEP);
        item.dataset.qty = q;
        paint(item);
        var isLow = q <= +item.dataset.low;
        msg.textContent = q <= 0 ? item.dataset.name + ' is out of stock.'
            : (isLow && !wasLow ? item.dataset.name + ' is now low. Time to reorder.'
            : (!isLow && wasLow ? item.dataset.name + ' is back above its alert level.'
            : item.dataset.name + ': ' + q + ' ' + item.dataset.unit + ' in stock.'));
    });
    document.getElementById('demoReset').addEventListener('click', function () {
        rows.forEach(function (r) { r.dataset.qty = r.dataset.start; paint(r); });
        msg.textContent = 'Demo reset. Sample numbers only.';
    });
})();
</script>
</body>
</html>