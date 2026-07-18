<?php

declare(strict_types=1);

/** @var string $title */
/** @var string $appName */
echo '<!DOCTYPE html>', "\n";
echo '<html>', "\n";
echo '<head>', "\n";
echo '    <meta charset="UTF-8">', "\n";
echo '    <title>', htmlspecialchars($title), ' — ', htmlspecialchars($appName), '</title>', "\n";
echo '    <link rel="stylesheet" href="/assets/style.css">', "\n";
echo '</head>', "\n";
echo '<body>', "\n";
$scriptBase = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
echo '<nav>', "\n";
echo '    <a href="', htmlspecialchars($scriptBase), '">Home</a>', "\n";
echo '    <a href="', htmlspecialchars($scriptBase), '/hello?name=Dev">Hello</a>', "\n";
echo '    <a href="', htmlspecialchars($scriptBase), '/contact">Contact</a>', "\n";
echo '</nav>', "\n";
echo '<main>', "\n";
// Thank-you is rendered via Router::renderContactThankYou → thankyou.php directly.
// Nesting thankyou.php in this elseif pollutes the layout CFG (MiniWebApp AOT #20507).
if ('Home' === $title) {
    include __DIR__ . '/home.php';
} elseif ('Hello' === $title) {
    include __DIR__ . '/hello.php';
} elseif ('Contact' === $title) {
    include __DIR__ . '/contact.php';
}
echo '</main>', "\n";
echo '</body>', "\n";
echo '</html>', "\n";
