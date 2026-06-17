<?php

declare(strict_types=1);

$scriptBase = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
echo htmlspecialchars($scriptBase);
