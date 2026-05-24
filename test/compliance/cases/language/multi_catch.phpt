--TEST--
Language: multi-type catch (A|B $e) (#1362)
--FILE--
<?php
class A {}
class B {}
class C extends A {}

try {
    throw new C();
} catch (A|B $e) {
    echo "C\n";
}

try {
    throw new B();
} catch (B|A $e) {
    echo "B\n";
}

try {
    throw new A();
} catch (B|C $e) {
    echo "wrong\n";
} catch (A $e) {
    echo "fallback\n";
}
--EXPECT--
C
B
fallback
