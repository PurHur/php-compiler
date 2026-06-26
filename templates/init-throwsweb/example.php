<?php

declare(strict_types=1);

/**
 * Throw/catch validation presenter (issue #2076).
 *
 * VM (GET only):
 *   ./phpc run examples/007-ThrowsWeb/example.php
 *
 * Serve (POST invalid email → caught HTML):
 *   ./phpc serve 127.0.0.1:8080 examples/007-ThrowsWeb
 *   curl -sf -X POST -d 'email=bad' http://127.0.0.1:8080/example.php | grep -i invalid
 *
 * Uncaught throws surface as HTTP 500 from phpc serve ([#152](https://github.com/PurHur/php-compiler/issues/152)).
 */
class ValidationError extends Exception
{
}

header('Content-Type: text/html; charset=UTF-8');

$method = isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';
$error = '';

if ('POST' === $method) {
    try {
        throw new ValidationError();
    } catch (ValidationError $e) {
        $error = 'Invalid email address';
    }
}

echo '<!DOCTYPE html><html><head><title>ThrowsWeb</title></head><body>';
echo '<h1>ThrowsWeb</h1>';

if ('' !== $error) {
    echo '<p class="invalid">', htmlspecialchars($error), "</p>\n";
} else {
    echo "<p>Submit an email. Invalid addresses throw and are caught.</p>\n";
}

echo '<form method="post"><label>Email <input name="email" type="text" value=""></label> ';
echo '<button type="submit">Validate</button></form>';
echo "</body></html>\n";
