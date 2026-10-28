<?php
require_once "auth.php";

date_default_timezone_set('Asia/Manila');

if (!function_exists('e')) {
    function e($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (is_logged_in()) {
    header('Location: dashboard.php');
    exit;
}

/*
 * The only account allowed to sign in. No database lookup is needed.
 * The password is stored as a hash, not as plain text.
 * Username: admin
 * To change the password, make a new hash and paste it below:
 *     php -r "echo password_hash('NewPassword', PASSWORD_DEFAULT);"
 */
const ADMIN_USERNAME      = 'admin';
const ADMIN_PASSWORD_HASH = '$2b$10$MBxyWysxSBIoWoVaZyxjK.SWlKb4niAtkbxyJD3obJTCmyd7wbc5u';

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

const MAX_FAILED_ATTEMPTS = 5;
const LOCKOUT_SECONDS     = 60;

$lockLeft = max(0, (int)($_SESSION['lock_until'] ?? 0) - time());

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

/* After a failed attempt, put the cursor where the person needs to retype. */
$focusPassword = ($error !== '' && $username !== '');
$describedBy   = ($error !== '' || $notice !== '') ? ' aria-describedby="formMsg"' : '';
$invalid       = ($error !== '') ? ' aria-invalid="true"' : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Sign in | Honey's Dressed Chicken and Frozen Food</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wdth,wght@12..96,75..100,600..800&family=Figtree:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="theme.css">
    <style>
        /* Layout and pieces unique to the sign-in page; colours and fields come from theme.css */
        html, body { height: 100%; }

        .login { display: grid; grid-template-columns: minmax(0, 5fr) minmax(0, 6fr); min-height: 100vh; min-height: 100dvh; }

        /* ---------- Brand side: glass panel with a stock-level preview ---------- */
        .brand-side { display: flex; flex-direction: column; justify-content: space-between; gap: 2.5rem;
            margin: 0; padding: clamp(2rem, 4vw, 3.5rem); border: 0; border-right: 1px solid var(--line); border-radius: 0;
            background: linear-gradient(160deg, rgba(124, 107, 255, .40), rgba(34, 211, 238, .08) 78%); }
        .brand { display: flex; align-items: center; gap: .85rem; }
        .brand-mark {
            display: grid; place-items: center; flex: none; width: 3rem; height: 3rem;
            border-radius: 50% 50% 50% 50% / 62% 62% 38% 38%;
            background: linear-gradient(135deg, var(--violet), var(--cyan)); color: #fff; font: 800 1.6rem var(--display);
            box-shadow: 0 10px 24px -10px rgba(124, 107, 255, .95), inset 0 1px 0 rgba(255, 255, 255, .35);
        }
        .brand strong { display: block; font: 700 1.35rem var(--display); line-height: 1.1; letter-spacing: -.01em; }
        .brand small { color: var(--muted); font-size: .85rem; }

        .brand-copy h2 {
            margin: 0 0 1rem; max-width: 11em; color: #fff;
            font: 700 clamp(2.2rem, 4.2vw, 3.6rem) var(--display);
            font-variation-settings: "wdth" 82, "opsz" 72; letter-spacing: -.03em; line-height: 1.02;
        }
        .brand-copy p { margin: 0; max-width: 27em; color: var(--muted); font-size: 1.05rem; line-height: 1.55; }

        /* Stock gauges: tick marks every 10%, white marker where "low stock" starts */
        .rack { display: grid; gap: 1.35rem; width: 100%; max-width: 26rem; padding: 1.25rem 1.35rem; border: 1px solid var(--line);
            border-radius: 16px; background: rgba(255, 255, 255, .05); }
        .rack p { display: flex; justify-content: space-between; gap: 1rem; margin: 0 0 .5rem; font-size: .9rem; color: var(--muted); }
        .rack p b { color: var(--text); font-weight: 600; }
        .rack .low p span { color: var(--low); font-weight: 600; }
        .bar { position: relative; display: block; height: 12px; border-radius: 999px; background: rgba(255, 255, 255, .1); }
        .bar::before { content: ""; position: absolute; inset: 0 auto 0 0; width: var(--w); border-radius: 999px;
            background: linear-gradient(90deg, var(--cyan), var(--violet)); }
        .rack .low .bar::before { background: linear-gradient(90deg, #FFB085, var(--out)); }
        .bar i { position: absolute; z-index: 1; top: -5px; bottom: -5px; left: 33%; width: 2px; border-radius: 1px; background: rgba(255, 255, 255, .85); }

        /* ---------- Form side ---------- */
        .form-side { display: grid; place-items: center; padding: 2.5rem 1.5rem; }
        .signin { width: 100%; max-width: 420px; padding: 2.25rem 2rem; border: 1px solid var(--line); border-radius: 22px;
            background: linear-gradient(145deg, rgba(255, 255, 255, .10), rgba(255, 255, 255, .035));
            -webkit-backdrop-filter: blur(16px); backdrop-filter: blur(16px);
            box-shadow: 0 24px 60px -28px rgba(0, 0, 0, .8), inset 0 1px 0 rgba(255, 255, 255, .08); }
        .signin h1 { margin: 0 0 .4rem; font: 700 2.5rem var(--display); letter-spacing: -.03em; line-height: 1.05;
            font-variation-settings: "wdth" 88, "opsz" 64; }
        .signin > p.lead { margin: 0 0 1.75rem; color: var(--muted); font-size: 1.02rem; }

        .msg { display: flex; align-items: flex-start; gap: .65rem; margin: 0 0 1.35rem; padding: .8rem .95rem;
            border-radius: 12px; font-size: .94rem; font-weight: 500; line-height: 1.4; }
        .msg svg { flex: none; width: 1.15rem; height: 1.15rem; margin-top: .08rem; }
        .msg.error  { background: var(--out-bg); color: var(--out-text); box-shadow: inset 0 0 0 1px rgba(255, 92, 112, .45); }
        .msg.notice { background: var(--ok-bg);  color: #9DF3E9;        box-shadow: inset 0 0 0 1px rgba(53, 224, 208, .38); }

        .field { margin-bottom: 1.2rem; }
        .field input { height: 3.1rem; font-size: 1.02rem; }
        .field input[aria-invalid="true"] { border-color: var(--out); }
        .field input[aria-invalid="true"]:focus { box-shadow: 0 0 0 4px rgba(255, 92, 112, .22); }
        .field input:-webkit-autofill { -webkit-box-shadow: 0 0 0 40px #141A4A inset; -webkit-text-fill-color: var(--text); caret-color: var(--text); }

        .pw { position: relative; }
        .field .pw input { padding-right: 4.75rem; }
        .pw button { position: absolute; top: 50%; right: .45rem; transform: translateY(-50%);
            min-width: 3.6rem; height: 2.2rem; padding: 0 .65rem; border: 0; border-radius: 8px;
            background: transparent; color: var(--cyan-soft); font: 600 .88rem var(--body); cursor: pointer; }
        .pw button:hover { background: rgba(255, 255, 255, .1); }

        .hint { margin: .5rem 0 0; color: var(--low); font-size: .88rem; font-weight: 500; }
        .hint[hidden] { display: none; }

        .submit { width: 100%; height: 3.2rem; margin-top: .6rem; border: 1px solid rgba(190, 182, 255, .45); border-radius: 12px;
            background: linear-gradient(135deg, var(--violet-a), var(--violet-b)); color: #fff; font: 700 1.02rem var(--body); cursor: pointer;
            box-shadow: 0 10px 26px -12px rgba(99, 88, 242, .95), inset 0 1px 0 rgba(255, 255, 255, .22); }
        .submit:hover { background: linear-gradient(135deg, #7468FF, #5649EA); }
        .submit:active { transform: translateY(1px); }
        .submit:disabled { opacity: .65; cursor: wait; transform: none; }

        .foot { margin: 1.75rem 0 0; padding-top: 1.1rem; border-top: 1px solid var(--line); font-size: .88rem; color: var(--muted); }
        .field input:focus-visible { outline: none; }

        /* ---------- Motion: gauges fill once on load; errors nudge once ---------- */
        @media (prefers-reduced-motion: no-preference) {
            .bar::before { animation: fill 1.2s cubic-bezier(.2, .7, .2, 1) both; animation-delay: var(--d, 0s); }
            .msg.error { animation: nudge .35s ease-out; }
            .submit { transition: background .15s, transform .1s; }
            @keyframes fill  { from { width: 0; } }
            @keyframes nudge { 25% { transform: translateX(-5px); } 55% { transform: translateX(4px); } 80% { transform: translateX(-2px); } }
        }

        /* ---------- Small screens: the brand panel becomes a slim header ---------- */
        @media (max-width: 820px) {
            .login { grid-template-columns: minmax(0, 1fr); grid-template-rows: auto 1fr; }
            .brand-side { flex-direction: row; justify-content: flex-start; padding: 1.1rem 1.25rem; gap: 0; border-right: 0; border-bottom: 1px solid var(--line); }
            .brand-copy, .rack { display: none; }
            .brand-mark { width: 2.6rem; height: 2.6rem; font-size: 1.4rem; }
            .brand strong { font-size: 1.2rem; }
            .form-side { align-items: start; padding: 2rem 1.25rem 2.5rem; }
            .signin { padding: 1.75rem 1.4rem; }
            .signin h1 { font-size: 2.2rem; }
        }
    </style>
</head>
<body>
<div class="login">

    <section class="brand-side coldroom">
        <div class="brand">
            <span class="brand-mark" aria-hidden="true">H</span>
            <span>
                <strong>Honey's</strong>
                <small>Dressed Chicken and Frozen Food</small>
            </span>
        </div>

        <div class="brand-copy">
            <h2>Inventory, sales, and deliveries in one place.</h2>
            <p>Sign in to check stock levels, ring up customers, and record new deliveries.</p>
        </div>

        <div class="rack" aria-hidden="true">
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

    <main class="form-side">
        <div class="signin">
            <h1>Sign in</h1>
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

            <form method="POST" action="login.php" id="loginForm" novalidate>
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
        </div>
    </main>
</div>

<script>
(function () {
    var pw = document.getElementById('password');
    var toggle = document.getElementById('togglePw');
    var form = document.getElementById('loginForm');
    var btn = document.getElementById('submitBtn');
    var caps = document.getElementById('capsHint');

    toggle.addEventListener('click', function () {
        var show = pw.type === 'password';
        pw.type = show ? 'text' : 'password';
        toggle.textContent = show ? 'Hide' : 'Show';
        toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
    });

    function checkCaps(ev) {
        if (ev.getModifierState) {
            caps.hidden = !ev.getModifierState('CapsLock');
        }
    }
    pw.addEventListener('keydown', checkCaps);
    pw.addEventListener('keyup', checkCaps);
    pw.addEventListener('blur', function () { caps.hidden = true; });

    form.addEventListener('submit', function () {
        btn.disabled = true;
        btn.textContent = 'Signing in…';
    });

    window.addEventListener('pageshow', function () {
        btn.disabled = false;
        btn.textContent = 'Sign in';
    });
})();
</script>
</body>
</html>