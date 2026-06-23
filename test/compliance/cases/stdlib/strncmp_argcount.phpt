--TEST--
stdlib strncmp()/strncasecmp() — too few arguments ArgumentCountError (#10993, ext/standard/string.c)
--FILE--
<?php
foreach (['strncmp', 'strncasecmp'] as $fn) {
    try {
        $fn('a', 'b');
    } catch (Throwable $e) {
        echo $fn, ': ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
?>
--EXPECTF--
%A
strncmp: ArgumentCountError: strncmp() expects exactly 3 arguments, 2 given
strncasecmp: ArgumentCountError: strncasecmp() expects exactly 3 arguments, 2 given
