--TEST--
stdlib strlen() scalar operands coerce without strict_types (#11263, ext/standard/string.c)
--FILE--
<?php
foreach ([false, true, 0, 1, 1.5, null] as $v) {
    try {
        echo gettype($v), '=', strlen($v), ' ';
    } catch (Throwable $e) {
        echo gettype($v), '=', get_class($e), ' ';
    }
}
echo "\n";
--EXPECT--
boolean=0 boolean=1 integer=1 integer=1 double=3 NULL=0 
