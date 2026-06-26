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
if ('Home' === $title) {
    include __DIR__ . '/home.php';
} elseif ('Hello' === $title) {
    include __DIR__ . '/hello.php';
} elseif ('Contact' === $title) {
    include __DIR__ . '/contact.php';
} elseif ('Thank you' === $title) {
    include __DIR__ . '/thankyou.php';
}
echo '</main>', "\n";
echo '</body>', "\n";
echo '</html>', "\n";
