--TEST--
Language: yield in static method — allowed (Zend parity, #35153 re-#4938)
--FILE--
<?php
class C {
    public static function gen(): Generator {
        yield 1;
        yield 2;
    }
}
foreach (C::gen() as $v) {
    echo $v;
}
echo "\n";
class D {
    public static function gen() {
        yield from [3, 4];
    }
}
foreach (D::gen() as $v) {
    echo $v;
}
echo "\n";
--EXPECT--
12
34
