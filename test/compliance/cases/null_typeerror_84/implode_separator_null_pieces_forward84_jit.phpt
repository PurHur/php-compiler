--TEST--
stdlib implode/join(",", null) dual-arg TypeError JIT on PROFILE=8.4 (#26278)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
foreach ([
    'implode(",", null)',
    'join(",", null)',
    'implode(",", ["a","b"])',
] as $c) {
    echo $c, ' => ';
    try {
        var_export(eval('return '.$c.';'));
    } catch (Throwable $e) {
        echo get_class($e), ':', $e->getMessage();
    }
    echo "\n";
}
?>
--EXPECT--
implode(",", null) => TypeError:implode(): If argument #1 ($separator) is of type string, argument #2 ($array) must be of type array, null given
join(",", null) => TypeError:join(): If argument #1 ($separator) is of type string, argument #2 ($array) must be of type array, null given
implode(",", ["a","b"]) => 'a,b'
