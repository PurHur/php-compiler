<?php

declare(strict_types=1);

/**
 * Bootstrap AOT lint: (string) cast lowering (TYPE_CAST_STRING JIT/VM, MiniWebApp index.php).
 */

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
echo $method;
