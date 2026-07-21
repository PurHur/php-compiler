--TEST--
stdlib vprintf()/vfprintf()/fprintf() null format DEP+coerce on 8.4 (#21514, formatted_print.c)
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
    var_export(vprintf(null, []));
    echo ($deps >= 1 ? ' DEP' : ''), " vprintf COERCE\n";
} catch (TypeError $e) {
    echo "vprintf TypeError\n";
}
$fp = fopen('php://memory', 'w+');
$prev = $deps;
try {
    var_export(vfprintf($fp, null, []));
    echo ($deps > $prev ? ' DEP' : ''), " vfprintf COERCE\n";
} catch (TypeError $e) {
    echo "vfprintf TypeError\n";
}
$prev = $deps;
try {
    var_export(fprintf($fp, null));
    echo ($deps > $prev ? ' DEP' : ''), " fprintf COERCE\n";
} catch (TypeError $e) {
    echo "fprintf TypeError\n";
}
fclose($fp);
--EXPECT--
0 DEP vprintf COERCE
0 DEP vfprintf COERCE
0 DEP fprintf COERCE
