<?php

declare(strict_types=1);

foreach (['Closure', 'Generator'] as $class) {
    try {
        new $class();
        echo "fail: new {$class}() succeeded\n";
        exit(1);
    } catch (Error $e) {
        echo $class, ':', $e::class, ':', $e->getMessage(), "\n";
    }
}

exit(0);
