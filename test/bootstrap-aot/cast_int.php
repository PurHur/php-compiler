<?php

declare(strict_types=1);

/**
 * Bootstrap AOT lint: (int) cast lowering (TYPE_CAST_INT JIT/VM, self-host VM.php).
 */

$a = (int) '42';
$b = (int) 3.9;

echo (string) ($a + $b);
