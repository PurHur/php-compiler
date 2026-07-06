--TEST--
Stdlib: get_defined_functions() $exclude_disabled rejected on PHP 8.2 reference profile (#4942, basic_functions.stub.php)
--FILE--
<?php
$ok = true;
try {
    get_defined_functions(true);
    $ok = false;
} catch (\ArgumentCountError $e) {
    if (!str_contains($e->getMessage(), 'expects exactly 0 arguments')) {
        $ok = false;
    }
}
echo $ok ? "ok\n" : "fail\n";
--EXPECT--
ok
