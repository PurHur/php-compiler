<?php

declare(strict_types=1);

$loaded = false;
spl_autoload_register(static function (string $class) use (&$loaded): void {
    if ('LazyClass' !== $class) {
        return;
    }
    $loaded = true;
});
class_exists('LazyClass');
var_export($loaded);
echo "\n";
