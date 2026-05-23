<?php

declare(strict_types=1);

/** @var string $title */
/** @var string $appName */
/** @var string $name */
$scriptBase = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>';
echo htmlspecialchars($title), ' — ', htmlspecialchars($appName);
echo '</title><link rel="stylesheet" href="/assets/style.css"></head><body><nav>';
echo '<a href="', htmlspecialchars($scriptBase), '">Home</a>';
echo '<a href="', htmlspecialchars($scriptBase), '/hello?name=Dev">Hello</a>';
echo '<a href="', htmlspecialchars($scriptBase), '/contact">Contact</a>';
echo '</nav><main>';
if ('Home' === $title) {
    include __DIR__ . '/home.php';
} elseif ('Hello' === $title) {
    include __DIR__ . '/hello.php';
} elseif ('Contact' === $title) {
    include __DIR__ . '/contact.php';
} elseif ('Thank you' === $title) {
    include __DIR__ . '/thankyou.php';
}
echo '</main></body></html>';
