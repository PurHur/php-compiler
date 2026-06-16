<?php
declare(strict_types=1);
try {
    takesInt('1');
    echo "strict:NO ERROR\n";
} catch (Throwable $e) {
    echo 'strict:', get_class($e), "\n";
}
