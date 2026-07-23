--TEST--
Language: __destruct on exception leave runs before catch / after finally (#22541)
--FILE--
<?php
class T {
    public function __construct(public string $n) {}
    public function __destruct() { echo "d:$this->n\n"; }
}

function throw_two(): void {
    $a = new T("a");
    $b = new T("b");
    throw new Exception("x");
}
try {
    throw_two();
} catch (Throwable $e) {
    echo "caught\n";
}
echo "after\n";

function throw_with_finally(): void {
    try {
        $a = new T("c");
        throw new Exception("y");
    } finally {
        echo "finally\n";
    }
}
try {
    throw_with_finally();
} catch (Throwable $e) {
    echo "caught2\n";
}

function nested_throw(): void {
    $x = new T("x");
    throw new Exception("z");
}
function throw_nested_finally(): void {
    try {
        $a = new T("d");
        nested_throw();
    } finally {
        echo "finally2\n";
    }
}
try {
    throw_nested_finally();
} catch (Throwable $e) {
    echo "caught3\n";
}
--EXPECT--
d:a
d:b
caught
after
finally
d:c
caught2
d:x
finally2
d:d
caught3
