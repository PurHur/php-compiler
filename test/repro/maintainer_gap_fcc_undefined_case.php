<?php

declare(strict_types=1);

// #26690 — FCC undefined-function Error must preserve identifier case (Zend/zend_execute_API.c).
try {
    $f = FooBar(...);
    echo "ok\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
