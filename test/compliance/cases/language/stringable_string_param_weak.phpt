--TEST--
Language: weak string params coerce Stringable via __toString (#22548, Zend/zend_execute_API.c)
--FILE--
<?php
class Explicit implements Stringable {
    public function __toString(): string {
        return 'E';
    }
}

class Implicit {
    public function __toString(): string {
        return 'I';
    }
}

class NotStringable {
    protected function __toString(): string {
        return 'hidden';
    }
}

class T {
    public string $p;
}

function f(string $x): void {
    echo $x;
}

function g(): string {
    return new Implicit();
}

f(new Explicit());
echo " ";
f(new Implicit());
echo " ";

$t = new T();
$t->p = new Explicit();
echo $t->p, " ";
echo g(), " ";

try {
    f(new NotStringable());
    echo "bad";
} catch (TypeError $e) {
    echo "reject";
}
echo "\n";
--EXPECT--
E I E I reject
