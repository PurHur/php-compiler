<?php
// Issue #29661 — class_alias(null) DEP must cite $class (Zend), not $original class.
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    echo "ERR[$errno]: $errstr\n";

    return true;
});

$rf = new ReflectionFunction('class_alias');
$params = $rf->getParameters();
echo 'rf0=', $params[0]->getName(), "\n";
echo 'rf1=', $params[1]->getName(), "\n";
echo 'rf2=', $params[2]->getName(), "\n";

try {
    var_export(class_alias(null, 'X29661'));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
