--TEST--
Backed enum bitwise/shift/unary operators throw TypeError (#5789, zend_operators.c)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }

try {
    E::A & E::B;
} catch (TypeError $e) {
    echo "bitwise &: TypeError\n";
}

try {
    E::A << 1;
} catch (TypeError $e) {
    echo "shift <<: TypeError\n";
}

try {
    -E::A;
} catch (TypeError $e) {
    echo "unary -: TypeError\n";
}

try {
    ~E::A;
} catch (TypeError $e) {
    echo "bitwise ~: TypeError\n";
}

$a = E::A;

try {
    $a << 1;
} catch (TypeError $e) {
    echo "var shift <<: TypeError\n";
}

try {
    -$a;
} catch (TypeError $e) {
    echo "var unary -: TypeError\n";
}

try {
    ~$a;
} catch (TypeError $e) {
    echo "var bitwise ~: TypeError\n";
}
?>
--EXPECT--
bitwise &: TypeError
shift <<: TypeError
unary -: TypeError
bitwise ~: TypeError
var shift <<: TypeError
var unary -: TypeError
var bitwise ~: TypeError
