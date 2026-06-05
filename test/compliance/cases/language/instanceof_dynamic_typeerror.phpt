--TEST--
Language: dynamic instanceof rejects non-string/non-object RHS (#4339)
--FILE--
<?php
declare(strict_types=1);

class C {}
$o = new C();

try {
    $rhs = 123;
    var_export($o instanceof $rhs);
} catch (Error $e) {
    echo 'Error', "\n";
}

try {
    $rhs = [];
    var_export($o instanceof $rhs);
} catch (Error $e) {
    echo 'Error', "\n";
}

$name = 'C';
var_export($o instanceof $name);
echo "\n";
--EXPECT--
Error
Error
true
