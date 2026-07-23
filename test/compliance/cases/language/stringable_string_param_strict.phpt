--TEST--
Language: strict_types string params reject Stringable with TypeError (#22548, Zend/zend_execute_API.c)
--FILE--
<?php
declare(strict_types=1);

class S implements Stringable {
    public function __toString(): string {
        return 'S';
    }
}

function f(string $x): void {
    echo $x;
}

try {
    f(new S());
    echo "bad\n";
} catch (TypeError $e) {
    echo "param_reject\n";
}
--EXPECT--
param_reject
