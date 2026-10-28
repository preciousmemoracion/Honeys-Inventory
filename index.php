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

        /* ---------- Product showcase (icons and graphics, right side of the hero) ---------- */
        .showcase { display: grid; gap: .9rem; min-width: 0; }
        .stage { position: relative; aspect-ratio: 4 / 4.3; overflow: hidden; border: 1px solid var(--line); border-radius: 24px; touch-action: pan-y;
            background: linear-gradient(145deg, rgba(255, 255, 255, .10), rgba(255, 255, 255, .035));
            box-shadow: 0 24px 60px -28px rgba(0, 0, 0, .8), inset 0 1px 0 rgba(255, 255, 255, .08); }
        .stage:focus-visible { outline: 2px solid var(--cyan-soft); outline-offset: 3px; }
        .slide { position: absolute; inset: 0; display: flex; flex-direction: column; justify-content: flex-end;
            opacity: 0; visibility: hidden; transition: opacity .5s ease, visibility 0s linear .5s; }
        .slide.active { opacity: 1; visibility: visible; transition: opacity .5s ease; }
        .art { position: absolute; inset: 0; display: grid; place-items: center; overflow: hidden;
            background: radial-gradient(circle at 50% 40%, var(--c1), transparent 68%); }
        .art::before { content: ""; position: absolute; left: 50%; top: 38%; width: 64%; aspect-ratio: 1; transform: translate(-50%, -50%);
            border-radius: 50%; border: 1px dashed rgba(255, 255, 255, .24); background: radial-gradient(circle, rgba(255, 255, 255, .09), transparent 70%); }
        .art::after { content: ""; position: absolute; left: 50%; top: 38%; width: 82%; aspect-ratio: 1; transform: translate(-50%, -50%);
            border-radius: 50%; border: 1px solid rgba(255, 255, 255, .08); }
        .art > svg { position: relative; width: 78%; height: auto; margin-bottom: 24%; }
        .fl { position: absolute; display: grid; place-items: center; width: 2.7rem; height: 2.7rem; border: 1px solid var(--line-strong); border-radius: 13px;
            background: rgba(255, 255, 255, .10); color: var(--cyan-soft); -webkit-backdrop-filter: blur(4px); backdrop-filter: blur(4px); }
        .fl svg { width: 55%; height: 55%; fill: currentColor; }
        .fl.warm { color: #FFD08A; }
        .fl.a { left: 11%; top: 13%; }
        .fl.b { right: 11%; top: 24%; }
        .fl.c { left: 17%; top: 47%; width: 2.3rem; height: 2.3rem; }
        /* Photos blend into the page: tinted with the theme colors and faded into the card */
        .showcase { position: relative; isolation: isolate; }
        .showcase::before { content: ""; position: absolute; z-index: -1; inset: -6% 0 4% 0; border-radius: 50%; pointer-events: none; filter: blur(26px);
            background: radial-gradient(closest-side, rgba(124, 107, 255, .38), transparent 72%), radial-gradient(closest-side at 82% 78%, rgba(34, 211, 238, .2), transparent 70%); }
        .stage { transition: border-color .25s, box-shadow .3s;
            box-shadow: 0 30px 70px -30px rgba(99, 88, 242, .55), inset 0 1px 0 rgba(255, 255, 255, .08); }
        .stage:hover { border-color: rgba(190, 182, 255, .4); }
        .stage::after { content: ""; position: absolute; inset: 0; z-index: 1; pointer-events: none; opacity: 0; transition: opacity .3s;
            background: radial-gradient(260px circle at var(--mx, 50%) var(--my, 30%), rgba(34, 211, 238, .16), transparent 70%); }
        @media (hover: hover) { .stage:hover::after { opacity: 1; } }

        .slide.has-photo .art { display: none; }
        .ph { position: absolute; inset: 0; overflow: hidden; isolation: isolate;
            -webkit-mask-image: linear-gradient(180deg, #000 52%, transparent 96%); mask-image: linear-gradient(180deg, #000 52%, transparent 96%); }
        .ph img { display: block; width: 100%; height: 100%; object-fit: cover; filter: saturate(1.08) contrast(1.03); }
        .ph::after { content: ""; position: absolute; inset: 0; pointer-events: none;
            background: linear-gradient(135deg, rgba(124, 107, 255, .32), rgba(34, 211, 238, .10) 55%, transparent),
                        linear-gradient(0deg, rgba(10, 14, 42, .45), transparent 50%); }

        /* White-background product shot: sits on a round plate that matches the theme */
        .slide.cutout .ph { inset: auto; left: 17%; top: 5%; width: 66%; height: auto; aspect-ratio: 1; border-radius: 50%;
            -webkit-mask-image: none; mask-image: none;
            background: radial-gradient(circle at 34% 28%, #FFFFFF 0%, #EEEBFF 62%, #D6D1FF 100%);
            box-shadow: 0 0 0 12px rgba(255, 255, 255, .05), 0 0 0 13px rgba(190, 182, 255, .25), 0 28px 60px -18px rgba(0, 0, 0, .75), 0 0 70px -10px rgba(124, 107, 255, .6); }
        .slide.cutout .ph img { object-fit: contain; mix-blend-mode: multiply; transform: scale(1.1); filter: none; }
        .slide.cutout .ph::after { background: radial-gradient(circle at 78% 86%, rgba(124, 107, 255, .3), transparent 62%); }

        /* Expand button and photo viewer */
        .ex { position: absolute; top: .75rem; left: .75rem; z-index: 2; display: none; place-items: center; width: 2.3rem; height: 2.3rem;
            border: 1px solid var(--line-strong); border-radius: 50%; background: rgba(10, 14, 42, .65); color: #fff; cursor: pointer;
            -webkit-backdrop-filter: blur(6px); backdrop-filter: blur(6px); transition: background .15s, transform .15s; }
        .ex svg { width: 1.1rem; height: 1.1rem; fill: currentColor; }
        .ex:hover { background: rgba(124, 107, 255, .8); transform: scale(1.08); }
        .stage:has(.slide.active.has-photo) .ex { display: grid; }
        .showcase.lb-open .pg { animation-play-state: paused !important; }
        dialog.lightbox { width: min(880px, calc(100% - 2rem)); max-height: calc(100dvh - 2rem); margin: auto; padding: 0; overflow: hidden; color: var(--text);
            border: 1px solid var(--line-strong); border-radius: 22px; background: linear-gradient(145deg, rgba(34, 40, 104, .97), rgba(14, 18, 54, .98));
            box-shadow: 0 30px 80px -20px rgba(0, 0, 0, .85), inset 0 1px 0 rgba(255, 255, 255, .1); }
        dialog.lightbox::backdrop { background: rgba(4, 6, 24, .78); -webkit-backdrop-filter: blur(6px); backdrop-filter: blur(6px); }
        .lightbox .x { position: absolute; top: .75rem; right: .75rem; z-index: 2; display: grid; place-items: center; width: 2.4rem; height: 2.4rem;
            border: 1px solid var(--line-strong); border-radius: 50%; background: rgba(10, 14, 42, .7); color: #fff; cursor: pointer; }
        .lightbox .x:hover { background: rgba(124, 107, 255, .8); }
        .lightbox .x svg { fill: currentColor; }
        .lightbox img { display: block; width: 100%; max-height: calc(100dvh - 10.5rem); object-fit: contain; background: #0A0E2A; }
        .lb-cap { padding: 1rem 1.4rem 1.3rem; }
        .lb-cap h3 { margin: 0 0 .2rem; font: 700 1.3rem var(--display); }
        .lb-cap p { margin: 0; color: var(--muted); }
        .cap { position: relative; padding: 3.5rem 1.4rem 1.4rem; background: linear-gradient(0deg, rgba(8, 11, 36, .95), rgba(8, 11, 36, .65) 60%, transparent); }
        .cap .chip { display: inline-block; margin-bottom: .5rem; padding: .15rem .65rem; border-radius: 999px; background: var(--ok-bg); color: #9DF3E9;
            font-size: .8rem; font-weight: 600; }
        .cap h3 { margin: 0 0 .25rem; font: 700 clamp(1.3rem, 2.4vw, 1.7rem) var(--display); letter-spacing: -.01em; }
        .cap p { margin: 0; color: var(--muted); font-size: .95rem; line-height: 1.45; }

        .nv { position: absolute; top: 42%; z-index: 2; display: grid; place-items: center; width: 2.6rem; height: 2.6rem; border: 1px solid var(--line-strong);
            border-radius: 50%; background: rgba(10, 14, 42, .65); color: #fff; cursor: pointer; -webkit-backdrop-filter: blur(6px); backdrop-filter: blur(6px);
            transition: background .15s, transform .15s; }
        .nv svg { fill: currentColor; }
        .nv:hover { background: rgba(124, 107, 255, .8); transform: scale(1.08); }
        .nv.prev { left: .75rem; }
        .nv.next { right: .75rem; }
        .pp { position: absolute; top: .75rem; right: .75rem; z-index: 2; padding: .3rem .7rem; border: 1px solid var(--line-strong); border-radius: 999px;
            background: rgba(10, 14, 42, .65); color: var(--cyan-soft); font: 600 .8rem var(--body); cursor: pointer; }
        .pp:hover { background: rgba(255, 255, 255, .14); }

        .thumbs { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .6rem; }
        .thumb { position: relative; overflow: hidden; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: .15rem;
            min-height: 3.6rem; padding: .5rem .4rem .6rem; border: 1px solid var(--line); border-radius: 12px;
            background: rgba(255, 255, 255, .05); color: var(--muted); font: 600 .85rem var(--body); cursor: pointer;
            transition: background .15s, color .15s, border-color .15s; }
        .thumb svg { width: 1.3rem; height: 1.3rem; fill: currentColor; }
        .thumb:hover { background: rgba(255, 255, 255, .1); color: var(--text); }
        .thumb.active { color: #fff; border-color: rgba(190, 182, 255, .55); background: rgba(124, 107, 255, .22); }
        .thumb .pg { position: absolute; left: 0; right: 0; bottom: 0; height: 3px; transform: scaleX(0); transform-origin: left;
            background: linear-gradient(90deg, var(--cyan), var(--violet)); }
        .thumb.active .pg { transform: scaleX(1); }
        .showcase.paused .pg { animation-play-state: paused !important; }
        @media (hover: hover) { .showcase:hover .pg { animation-play-state: paused; } }

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
            .msg.error { animation: nudge .35s ease-out; }
            dialog.signin[open] { animation: pop .22s ease-out; }
            .thumb.active .pg { transform: scaleX(0); animation: prog 5s linear forwards; }
            .slide.active .art > svg { animation: bob 4.5s ease-in-out infinite; }
            .slide.active .fl { animation: drift 5s ease-in-out infinite; }
            .slide.active .fl.b { animation-delay: -1.7s; }
            .slide.active .fl.c { animation-delay: -3.2s; }
            .slide.has-photo.active .ph img { animation: kb 9s ease-out both; }
            .slide.cutout.has-photo.active .ph img { animation: none; }
            .slide.has-photo .ph { transform: translate3d(calc(var(--px, 0) * -10px), calc(var(--py, 0) * -8px), 0) scale(1.07); transition: transform .3s ease-out; }
            .slide.cutout.has-photo .ph { transform: translate3d(calc(var(--px, 0) * 9px), calc(var(--py, 0) * 7px), 0); }
            dialog.lightbox[open] { animation: pop .22s ease-out; }
            @keyframes nudge { 25% { transform: translateX(-5px); } 55% { transform: translateX(4px); } 80% { transform: translateX(-2px); } }
            @keyframes pop   { from { opacity: 0; transform: translateY(10px) scale(.98); } }
            @keyframes prog  { to { transform: scaleX(1); } }
            @keyframes bob   { 50% { transform: translateY(-8px) rotate(-1deg); } }
            @keyframes drift { 50% { transform: translateY(-9px); } }
            @keyframes kb    { from { transform: scale(1.08); } to { transform: scale(1); } }
        }

        @media (max-width: 900px) {
            .hero { grid-template-columns: minmax(0, 1fr); }
            .features { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .stage { aspect-ratio: 4 / 4.2; }
            .showcase { width: 100%; max-width: 540px; margin-inline: auto; }
            .slide.cutout .ph { left: 20%; width: 60%; }
        }
        @media (max-width: 560px) {
            .features { grid-template-columns: minmax(0, 1fr); }
            .brand small { display: none; }
            dialog.signin { padding: 1.8rem 1.25rem 1.5rem; }
        }
        @media (max-width: 430px) {
            .stage { aspect-ratio: 1 / 1.12; }
            .slide.cutout .ph { left: 18%; width: 64%; }
            .nv { width: 2.3rem; height: 2.3rem; }
            .fl { width: 2.3rem; height: 2.3rem; }
            .fl.c { display: none; }
            .thumb { font-size: .78rem; min-height: 3.3rem; }
        }

        /* ---------- Enhancements: nav, reveal, contact ---------- */
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

        <!-- Product showcase. Photos go in an "images" folder next to index.php:
             whole-chicken.jpeg, chicken-cuts.jpg, hotdog.jpg, frozen-food.jpg
             A photo covers its slide automatically. If a file is missing, the drawn graphic shows instead. -->
        <div class="showcase reveal" id="showcase" style="--rd: .12s" role="region" aria-roledescription="carousel" aria-label="Honey's products">
            <div class="stage" id="stage" tabindex="0">

                <!-- 1. Whole dressed chicken -->
                <article class="slide active cutout" style="--c1: rgba(255, 176, 90, .5)" aria-label="Whole dressed chicken">
                    <div class="art" aria-hidden="true">
                        <svg viewBox="0 0 400 300">
                            <ellipse cx="200" cy="262" rx="150" ry="20" fill="#000" opacity=".25"/>
                            <path d="M125 222 L88 252 a13 13 0 1 0 20 15 L158 238Z" fill="#E9A85C"/>
                            <path d="M275 222 L312 252 a13 13 0 1 1 -20 15 L242 238Z" fill="#E9A85C"/>
                            <path d="M105 170C105 108 155 78 208 82c62 5 92 46 87 98-5 48-55 68-105 63-50-5-85-26-85-73z" fill="#F7C98B"/>
                            <ellipse cx="200" cy="150" rx="64" ry="42" fill="#FBDDAE"/>
                            <path d="M120 150c-30 5-48 28-38 48 20-2 40-14 50-32z" fill="#EDB264"/>
                            <path d="M280 150c30 5 48 28 38 48-20-2-40-14-50-32z" fill="#EDB264"/>
                        </svg>
                        <span class="fl warm a"><svg viewBox="0 0 24 24"><path d="M12 2l2.2 7.8L22 12l-7.8 2.2L12 22l-2.2-7.8L2 12l7.8-2.2z"/></svg></span>
                        <span class="fl b"><svg viewBox="0 0 24 24"><path d="M11 2h2v3.1a7 7 0 0 1 0 13.8V22h-2v-3.1A7 7 0 0 1 11 5.1V2zm1 5a5 5 0 1 0 0 10 5 5 0 0 0 0-10z"/></svg></span>
                    </div>
                    <div class="ph"><img src="images/img/whole-chicken.jpeg" alt="Whole dressed chicken" decoding="async" onload="this.closest('.slide').classList.add('has-photo')" onerror="this.parentNode.remove()"></div>
                    <div class="cap"><span class="chip">Fresh daily</span><h3>Whole dressed chicken</h3><p>Cleaned, dressed and ready for the pot or the grill.</p></div>
                </article>

                <!-- 3. Hotdogs -->
                <article class="slide" style="--c1: rgba(255, 90, 110, .4)" aria-label="Hotdogs and sausages">
                    <div class="art" aria-hidden="true">
                        <svg viewBox="0 0 400 300">
                            <ellipse cx="200" cy="250" rx="150" ry="18" fill="#000" opacity=".25"/>
                            <rect x="60" y="120" width="280" height="62" rx="31" fill="#EDB05E"/>
                            <rect x="48" y="108" width="304" height="48" rx="24" fill="#D9473F"/>
                            <path d="M80 122q20-14 40 0t40 0 40 0 40 0 40 0" stroke="#FFD84D" stroke-width="7" fill="none" stroke-linecap="round"/>
                            <rect x="60" y="140" width="280" height="60" rx="30" fill="#F6C77E"/>
                            <rect x="70" y="150" width="260" height="10" rx="5" fill="#fff" opacity=".25"/>
                        </svg>
                        <span class="fl a"><svg viewBox="0 0 24 24"><path d="M11 2h2v7.6l5.9-3.4 1 1.7L14 11.3l5.9 3.4-1 1.7-5.9-3.4V22h-2v-9.6l-5.9 3.4-1-1.7L10 11.3 4.1 7.9l1-1.7L11 9.6V2z"/></svg></span>
                        <span class="fl warm b"><svg viewBox="0 0 24 24"><path d="M12 2l2.2 7.8L22 12l-7.8 2.2L12 22l-2.2-7.8L2 12l7.8-2.2z"/></svg></span>
                    </div>
                    <div class="ph"><img src="images/img/hotdog.jpg" alt="Frozen hotdogs and sausages" decoding="async" onload="this.closest('.slide').classList.add('has-photo')" onerror="this.parentNode.remove()"></div>
                    <div class="cap"><span class="chip">Frozen</span><h3>Hotdogs and sausages</h3><p>Packs for the kids' snack and the weekend barbecue.</p></div>
                </article>

                <!-- 4. Frozen favorites -->
                <article class="slide" style="--c1: rgba(34, 211, 238, .4)" aria-label="Frozen favorites">
                    <div class="art" aria-hidden="true">
                        <svg viewBox="0 0 400 300">
                            <ellipse cx="200" cy="262" rx="140" ry="18" fill="#000" opacity=".25"/>
                            <rect x="125" y="40" width="150" height="205" rx="16" fill="#22D3EE" fill-opacity=".22" stroke="#9EEBFA" stroke-width="3"/>
                            <rect x="125" y="40" width="150" height="26" rx="10" fill="#9EEBFA" fill-opacity=".5"/>
                            <g stroke="#E6FBFF" stroke-width="5" stroke-linecap="round" transform="translate(200 120)"><path d="M0-34V34"/><path d="M-30-17L30 17"/><path d="M-30 17L30-17"/></g>
                            <path d="M170 215c-18-4-24-26-8-36 14-9 36-2 38 14 2 16-14 28-30 22z" fill="#EBB24E"/>
                            <path d="M225 222c-16-2-24-20-12-32 12-11 32-6 36 8 4 15-10 28-24 24z" fill="#F2C161"/>
                        </svg>
                        <span class="fl a"><svg viewBox="0 0 24 24"><path d="M11 2h2v7.6l5.9-3.4 1 1.7L14 11.3l5.9 3.4-1 1.7-5.9-3.4V22h-2v-9.6l-5.9 3.4-1-1.7L10 11.3 4.1 7.9l1-1.7L11 9.6V2z"/></svg></span>
                        <span class="fl b"><svg viewBox="0 0 24 24"><path d="M15 13V5a3 3 0 0 0-6 0v8a5 5 0 1 0 6 0zm-3-9a1 1 0 0 1 1 1v3h-2V5a1 1 0 0 1 1-1z"/></svg></span>
                        <span class="fl c"><svg viewBox="0 0 24 24"><path d="M11 2h2v7.6l5.9-3.4 1 1.7L14 11.3l5.9 3.4-1 1.7-5.9-3.4V22h-2v-9.6l-5.9 3.4-1-1.7L10 11.3 4.1 7.9l1-1.7L11 9.6V2z"/></svg></span>
                    </div>
                    <div class="ph"><img src="images/img/frozen-food.jpg" alt="Packs of frozen ham, tocino and sausages" decoding="async" onload="this.closest('.slide').classList.add('has-photo')" onerror="this.parentNode.remove()"></div>
                    <div class="cap"><span class="chip">Keep frozen</span><h3>Frozen favorites</h3><p>Ham, tocino and other ready-to-cook packs, kept cold until you take them home.</p></div>
                </article>

                <button class="nv prev" type="button" id="ssPrev" aria-label="Previous"><svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true"><path d="M15.41 7.41 14 6l-6 6 6 6 1.41-1.41L10.83 12z"/></svg></button>
                <button class="nv next" type="button" id="ssNext" aria-label="Next"><svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true"><path d="M8.59 16.59 13.17 12 8.59 7.41 10 6l6 6-6 6z"/></svg></button>
                <button class="pp" type="button" id="ssPause" aria-pressed="false">Pause</button>
                <button class="ex" type="button" id="ssExpand" aria-label="View larger photo"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 14H5v5h5v-2H7v-3zm-2-4h2V7h3V5H5v5zm12 7h-3v2h5v-5h-2v3zM14 5v2h3v3h2V5h-5z"/></svg></button>
            </div>

          
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

<dialog class="lightbox" id="lightbox" aria-label="Product photo">
    <button class="x" type="button" data-close-lb aria-label="Close photo">
        <svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><path d="M19 6.41 17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
    </button>
    <img src="" alt="">
    <div class="lb-cap"><h3></h3><p></p></div>
</dialog>

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

    /* Product showcase */
    var box = document.getElementById('showcase');
    if (box) {
        var slides = Array.prototype.slice.call(box.querySelectorAll('.slide'));
        var thumbs = Array.prototype.slice.call(box.querySelectorAll('.thumb'));
        var stage = document.getElementById('stage');
        var pause = document.getElementById('ssPause');
        var cur = 0;

        function go(n) {
            cur = (n + slides.length) % slides.length;
            slides.forEach(function (s, k) {
                s.classList.toggle('active', k === cur);
                s.setAttribute('aria-hidden', k === cur ? 'false' : 'true');
            });
            thumbs.forEach(function (t, k) {
                t.classList.toggle('active', k === cur);
                if (k === cur) t.setAttribute('aria-current', 'true'); else t.removeAttribute('aria-current');
            });
        }
        thumbs.forEach(function (t, k) { t.addEventListener('click', function () { go(k); }); });
        document.getElementById('ssPrev').addEventListener('click', function () { go(cur - 1); });
        document.getElementById('ssNext').addEventListener('click', function () { go(cur + 1); });

        // The progress bar on the active tab drives autoplay: when it finishes, go to the next slide.
        box.addEventListener('animationend', function (e) {
            if (e.target.classList && e.target.classList.contains('pg')) go(cur + 1);
        });

        pause.addEventListener('click', function () {
            var p = box.classList.toggle('paused');
            pause.textContent = p ? 'Play' : 'Pause';
            pause.setAttribute('aria-pressed', p ? 'true' : 'false');
        });

        stage.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowLeft') go(cur - 1);
            if (e.key === 'ArrowRight') go(cur + 1);
        });

        var sx = null;
        stage.addEventListener('pointerdown', function (e) { sx = e.clientX; });
        stage.addEventListener('pointerup', function (e) {
            if (sx === null) return;
            var dx = e.clientX - sx; sx = null;
            if (Math.abs(dx) > 40) go(dx < 0 ? cur + 1 : cur - 1);
        });
        stage.addEventListener('pointercancel', function () { sx = null; });

        /* Pointer parallax and light (mouse only) */
        stage.addEventListener('pointermove', function (e) {
            if (e.pointerType !== 'mouse') return;
            var r = stage.getBoundingClientRect();
            stage.style.setProperty('--px', ((e.clientX - r.left) / r.width * 2 - 1).toFixed(3));
            stage.style.setProperty('--py', ((e.clientY - r.top) / r.height * 2 - 1).toFixed(3));
            stage.style.setProperty('--mx', (e.clientX - r.left) + 'px');
            stage.style.setProperty('--my', (e.clientY - r.top) + 'px');
        });
        stage.addEventListener('pointerleave', function () {
            stage.style.setProperty('--px', 0);
            stage.style.setProperty('--py', 0);
        });

        /* Larger photo viewer */
        var lb = document.getElementById('lightbox');
        var ex = document.getElementById('ssExpand');
        if (lb && ex && typeof lb.showModal === 'function') {
            ex.addEventListener('click', function () {
                var s = slides[cur], im = s.querySelector('.ph img');
                if (!im) return;
                var li = lb.querySelector('img');
                li.src = im.currentSrc || im.src;
                li.alt = im.alt;
                lb.querySelector('h3').textContent = s.querySelector('.cap h3').textContent;
                lb.querySelector('p').textContent = s.querySelector('.cap p').textContent;
                box.classList.add('lb-open');
                lb.showModal();
            });
            lb.querySelector('[data-close-lb]').addEventListener('click', function () { lb.close(); });
            lb.addEventListener('mousedown', function (e) { if (e.target === lb) lb.close(); });
            lb.addEventListener('close', function () { box.classList.remove('lb-open'); });
        }

        go(0);
    }
})();
</script>
</body>
</html>