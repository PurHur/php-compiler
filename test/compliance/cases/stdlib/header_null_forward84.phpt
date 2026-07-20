--TEST--
stdlib header(null) — DEP+coerce on 8.4 forward profile (#21234, reverts #19224, ext/standard/head.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
$deps = 0;
set_error_handler(static function (int $no, string $msg) use (&$deps): bool {
    if (E_DEPRECATED === $no) {
        ++$deps;
    }
    return true;
});
try {
    header(null);
    echo ($deps >= 1 ? 'DEP ' : ''), "ok\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    header('');
    echo "empty ok\n";
} catch (TypeError $e) {
    echo 'empty: ', $e->getMessage(), "\n";
}
?>
--EXPECT--
DEP ok
empty ok
