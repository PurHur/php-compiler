<?php

declare(strict_types=1);

/** @var string $title */
/** @var string $appName */
/** @var string $guestName */
$scriptBase = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
if ('Hello' === $title) {
    include __DIR__ . '/hello.php';
}
