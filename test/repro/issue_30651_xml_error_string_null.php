<?php

error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    echo "ERR[$errno]: $errstr\n";

    return true;
});

try {
    var_export(xml_error_string(null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    xml_error_string([]);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

$rf = new ReflectionFunction('xml_error_string');
foreach ($rf->getParameters() as $p) {
    echo 'param:', $p->getName(), "\n";
}
