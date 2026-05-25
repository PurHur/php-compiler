<?php

declare(strict_types=1);

/**
 * Session flash message across requests (issue #1881).
 *
 * VM (single request):
 *   ./phpc run examples/005-SessionsWeb/example.php
 *
 * Serve (two requests with cookie jar):
 *   ./phpc serve 127.0.0.1:8080 examples/005-SessionsWeb
 *   curl -s -c /tmp/sess.jar 'http://127.0.0.1:8080/example.php'
 *   curl -s -b /tmp/sess.jar -c /tmp/sess.jar -X POST -d 'message=Saved' 'http://127.0.0.1:8080/example.php'
 *   curl -s -b /tmp/sess.jar 'http://127.0.0.1:8080/example.php'
 *
 * AOT: link when project path ready ([#1891](https://github.com/PurHur/php-compiler/issues/1891)).
 */
session_start();

$method = isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';
if ('POST' === $method) {
    $_SESSION['flash'] = isset($_POST['message']) ? (string) $_POST['message'] : 'saved';
    session_write_close();
    header('Location: /example.php', true, 303);
    exit;
}

$flash = '';
if (isset($_SESSION['flash'])) {
    $flash = (string) $_SESSION['flash'];
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
