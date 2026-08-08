<?php
// Issue #29150 — PROFILE≤8.2 rejects class_alias of internal classes (Zend 8.1/8.2 ValueError).
error_reporting(E_ALL);
foreach (['stdClass', 'Exception', 'Traversable'] as $class) {
    $alias = 'Alias29150_'.str_replace('\\', '_', $class).'_'.bin2hex(random_bytes(2));
    try {
        var_export(class_alias($class, $alias));
    } catch (Throwable $e) {
        echo get_class($e), ':', $e->getMessage();
    }
    echo "\n";
}
class User29150 {}
var_export(class_alias(User29150::class, 'UserAlias29150_'.bin2hex(random_bytes(2))));
echo "\n";
