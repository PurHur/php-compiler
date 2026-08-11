--TEST--
Language: method/property on true|false use true/false not bool (#30054, zend_execute.c)
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $message): bool {
    if (E_WARNING === $errno) {
        echo 'W:', $message, "\n";
    }

    return true;
});

foreach ([false, true] as $x) {
    try {
        $x->foo();
    } catch (Throwable $e) {
        echo 'M:', $e->getMessage(), "\n";
    }
}
foreach ([false, true] as $x) {
    $v = $x->foo;
    echo 'P:', var_export($v, true), "\n";
}
$x = 1;
$v = $x->foo;
echo 'I:', var_export($v, true), "\n";
--EXPECT--
M:Call to a member function foo() on false
M:Call to a member function foo() on true
W:Attempt to read property "foo" on false
P:NULL
W:Attempt to read property "foo" on true
P:NULL
W:Attempt to read property "foo" on int
I:NULL
