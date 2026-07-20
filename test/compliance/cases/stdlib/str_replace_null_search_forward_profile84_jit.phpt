--TEST--
stdlib str_replace()/str_ireplace() null $search soft-null on 8.4 JIT (#21189)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (): bool { return true; });
foreach ([
    'str_replace' => static fn () => str_replace(null, 'b', 'hay'),
    'str_ireplace' => static fn () => str_ireplace(null, 'b', 'Hay'),
] as $label => $factory) {
    try {
        $r = $factory();
        echo "$label: OK ", var_export($r, true), "\n";
    } catch (TypeError $e) {
        echo $label.': '.$e->getMessage()."\n";
    }
}
?>
--EXPECT--
str_replace: OK 'hay'
str_ireplace: OK 'Hay'
