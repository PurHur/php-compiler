--TEST--
json/stdlib soft-null batch — json_decode/unserialize on 8.4 JIT; parse_str TypeError (#21223, #21380)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
error_reporting(E_ALL);
$seen = [];
set_error_handler(static function (int $no, string $str) use (&$seen): bool {
    if (E_DEPRECATED === $no) {
        $seen[] = $str;
    }
    return true;
});
foreach ([
    'json_decode' => static fn () => json_decode(null),
    'unserialize' => static fn () => unserialize(null),
    'parse_str' => static function () {
        $x = null;
        parse_str(null, $x);
        return $x;
    },
] as $n => $fn) {
    try {
        $v = $fn();
        echo $n, '=', var_export($v, true), "\n";
    } catch (Throwable $e) {
        echo $n, '=', get_class($e), "\n";
    }
}
restore_error_handler();
echo 'depr=', (int) (count($seen) >= 2), "\n";
?>
--EXPECT--
json_decode=NULL
unserialize=false
parse_str=TypeError
depr=1
