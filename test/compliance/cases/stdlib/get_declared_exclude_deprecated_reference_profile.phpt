--TEST--
Stdlib: get_declared_* $exclude_deprecated rejected on every profile (#27900 / #12403, zend_builtin_functions.stub.php)
--FILE--
<?php
$ok = true;
foreach (['get_declared_classes', 'get_declared_interfaces', 'get_declared_traits'] as $fn) {
    try {
        $fn(true);
        $ok = false;
    } catch (\ArgumentCountError $e) {
        if (!str_contains($e->getMessage(), 'expects exactly 0 arguments')) {
            $ok = false;
        }
    }
}
echo $ok ? "ok\n" : "fail\n";
--EXPECT--
ok
