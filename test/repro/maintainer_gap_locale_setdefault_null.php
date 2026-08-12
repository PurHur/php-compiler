<?php
declare(strict_types=1);
foreach ([
    'oop' => static fn () => Locale::setDefault(null),
    'proc' => static fn () => locale_set_default(null),
] as $name => $call) {
    try {
        var_export($call());
        echo "\n";
    } catch (Throwable $e) {
        echo $name, ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
