--TEST--
Language: object relational compare throws TypeError (#3445, Zend zend_operators.c)
--FILE--
<?php
class A {}
$a = new A();
$b = new A();
$msg = 'Object of class A could not be converted to number';

try {
    $a < $b;
    echo "no error for <\n";
} catch (TypeError $e) {
    echo $e->getMessage() === $msg ? "TypeError: <\n" : "wrong: <\n";
} catch (Throwable $e) {
    echo "Throwable: <\n";
}

try {
    $a <= $b;
    echo "no error for <=\n";
} catch (TypeError $e) {
    echo $e->getMessage() === $msg ? "TypeError: <=\n" : "wrong: <=\n";
} catch (Throwable $e) {
    echo "Throwable: <=\n";
}

try {
    $a > $b;
    echo "no error for >\n";
} catch (TypeError $e) {
    echo $e->getMessage() === $msg ? "TypeError: >\n" : "wrong: >\n";
} catch (Throwable $e) {
    echo "Throwable: >\n";
}

try {
    $a >= $b;
    echo "no error for >=\n";
} catch (TypeError $e) {
    echo $e->getMessage() === $msg ? "TypeError: >=\n" : "wrong: >=\n";
} catch (Throwable $e) {
    echo "Throwable: >=\n";
}

try {
    $a <=> $b;
    echo "no error for <=>\n";
} catch (TypeError $e) {
    echo $e->getMessage() === $msg ? "TypeError: <=>\n" : "wrong: <=>\n";
} catch (Throwable $e) {
    echo "Throwable: <=>\n";
}
?>
--EXPECT--
TypeError: <
TypeError: <=
TypeError: >
TypeError: >=
TypeError: <=>
