--TEST--
Language: return from nested call must not run caller's finally early (#22541)
--FILE--
<?php
class T {
    public function __construct(public string $n) {
        echo "ctor:$n\n";
    }
    public function __destruct() {
        echo "d:$this->n\n";
    }
}
function f(): void {
    try {
        echo "before\n";
        $a = new T("a");
        echo "after_new\n";
    } finally {
        echo "finally\n";
    }
}
f();
echo "done\n";
--EXPECT--
before
ctor:a
after_new
finally
done
d:a
