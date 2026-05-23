<?php

declare(strict_types=1);

$scriptBase = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
header('Content-Type: text/html; charset=UTF-8');
echo htmlspecialchars($scriptBase);
