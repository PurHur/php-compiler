--TEST--
stdlib ob_implicit_flush() — int 0/1 coercion (php-src output.c, #12815)
--FILE--
<?php
ob_implicit_flush(1);
ob_implicit_flush(0);
var_dump(ob_implicit_flush(true));
var_dump(ob_implicit_flush());
echo "ok\n";
?>
--EXPECT--
NULL
NULL
ok
