<?php

declare(strict_types=1);

/** @var string $title */
$scriptBase = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
echo htmlspecialchars($title), "\n";
