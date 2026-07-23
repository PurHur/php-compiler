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

function f(string $x): void {
    echo $x;
}

f(new Explicit());
echo " ";
f(new Implicit());
echo " ";
try {
    f(new NotStringable());
    echo "bad";
} catch (TypeError $e) {
    echo "reject";
}
echo "\n";

class T {
    public string $p;
}
$t = new T();
$t->p = new Explicit();
echo $t->p, "\n";

function g(): string {
    return new Implicit();
}
echo g(), "\n";
--EXPECT--
E I reject
E
I
