<?php

declare(strict_types=1);

// Session flash across requests — see examples/005-SessionsWeb/README.md (#1881, #9226).
session_start();

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ('POST' === $method) {
    $_SESSION['flash'] = (string) ($_POST['message'] ?? 'saved');
    session_write_close();
    header('Location: /example.php', true, 303);
    exit;
}

$flash = (string) ($_SESSION['flash'] ?? '');
if ('' !== $flash) {
    unset($_SESSION['flash']);
}

header('Content-Type: text/html; charset=UTF-8');
echo '<!DOCTYPE html><html><head><title>SessionsWeb</title></head><body>';
echo '<h1>SessionsWeb</h1>';
if ('' !== $flash) {
    echo '<p class="flash">Flash: ', $flash, "</p>\n";
} else {
    echo "<p>No flash message yet.</p>\n";
}
echo '<form method="post"><label>Message <input name="message" value="hello"></label> ';
echo '<button type="submit">Set flash</button></form>';
echo "</body></html>\n";
session_write_close();
