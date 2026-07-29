--TEST--
Language: (void) cast evaluates operand and yields null (#7421, zend_execute.c)
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
$x = (void)(1 + 2);
var_export($x);
echo "\n";

function side(): int {
    echo "side\n";
    return 99;
}
$y = (void)side();
var_export($y);
echo "\n";

ob_start();
echo (void)1;
echo strlen(ob_get_clean()) === 0 ? "silent\n" : "bad\n";
--EXPECT--
NULL
side
NULL
silent
