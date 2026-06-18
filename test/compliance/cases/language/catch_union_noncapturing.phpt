--TEST--
Language: non-capturing union catch (A|B) (#9766)
--FILE--
<?php
declare(strict_types=1);

class A extends LogicException {}
class B extends TypeError {}

try {
    throw new A('a');
} catch (LogicException|TypeError) {
    echo "A\n";
}

try {
    throw new B('b');
} catch (LogicException|TypeError) {
    echo "B\n";
}

try {
    throw new RuntimeException('miss');
} catch (LogicException|TypeError) {
    echo "wrong\n";
} catch (Exception) {
    echo "fallback\n";
}
--EXPECT--
A
B
fallback
