<?php

declare(strict_types=1);

// #28003 — ClassName::class(...) FCC must throw catchable Error (Zend/zend_compile.c / zend_execute_API.c).
class C
{
}

try {
    $f = C::class(...);
    echo "ok\n";
} catch (Throwable $e) {
    echo 'caught:', get_class($e), ':', $e->getMessage(), "\n";
}
echo "after\n";
