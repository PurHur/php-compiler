<?php
declare(strict_types=1);
require __DIR__.'/callee.php';
try {
    takesInt('1');
    echo "NO ERROR\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
