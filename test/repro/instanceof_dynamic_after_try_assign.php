<?php
declare(strict_types=1);

// #26490: first CV assign inside try must not clobber outer `$o` for later instanceof.
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
