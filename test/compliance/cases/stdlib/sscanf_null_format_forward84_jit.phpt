--TEST--
stdlib sscanf() null $format DEP+coerce on 8.4 — JIT (#21521, ext/standard/sscanf.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
error_reporting(E_ALL);
$deps = 0;
set_error_handler(static function (int $no, string $msg) use (&$deps): bool {
    if (E_DEPRECATED === $no) {
        ++$deps;
        return true;
    }
    return false;
});
try {
    var_export(sscanf('abc', null));
    echo ($deps >= 1 ? ' DEP' : ''), " COERCE\n";
} catch (TypeError $e) {
    echo "TypeError\n";
}
?>
--EXPECT--
array (
) DEP COERCE
