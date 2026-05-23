<?php

declare(strict_types=1);

/** @var string $title */
/** @var string $appName */
echo $title, ' — ', $appName, "\n";
if ('Home' === $title) {
    include __DIR__ . '/home.php';
}
