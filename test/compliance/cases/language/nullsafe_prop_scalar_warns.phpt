--TEST--
Language: nullsafe ?-> property on scalar warns like plain -> (#26365, re-#18026, Zend/zend_vm_def.h)
--FILE--
<?php
set_error_handler(static function (int $errno, string $message): bool {
    if (E_WARNING === $errno) {
        echo 'W:', $message, "\n";
    }

    return true;
});

$x = false;
$y = $x?->foo;
echo 'bool=', var_export($y, true), "\n";
$y = (1)?->foo;
echo 'int=', var_export($y, true), "\n";
$n = null;
$y = $n?->foo;
echo 'null=', var_export($y, true), "\n";
--EXPECT--
W:Attempt to read property "foo" on false
bool=NULL
W:Attempt to read property "foo" on int
int=NULL
null=NULL
