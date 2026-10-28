<?php
require_once "auth.php";

// Sign out only on a POST with a valid token, so another site can't log people out.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['csrf'] ?? null)) {
    destroy_session();
    header('Location: login.php?msg=loggedout');
    exit;
}

header('Location: dashboard.php');
exit;