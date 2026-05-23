<?php

declare(strict_types=1);

/** @var string $title */
/** @var string $appName */
$scriptBase = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
echo htmlspecialchars($title), ' — ', htmlspecialchars($appName), "\n";
if ('Home' === $title) {
    include __DIR__ . '/home.php';
} elseif ('Hello' === $title) {
    include __DIR__ . '/hello.php';
}
