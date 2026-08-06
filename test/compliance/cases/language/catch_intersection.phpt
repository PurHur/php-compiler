--TEST--
Language: catch intersection types (A&B $e) (#28205)
--FILE--
<?php
class A extends Exception implements Countable {
    public function count(): int { return 0; }
}
class B extends Exception {}

try {
    throw new A();
} catch (Countable&Throwable $e) {
    echo "caught:", get_class($e), "\n";
}

try {
    throw new B();
} catch (Countable&Throwable $e) {
    echo "wrong\n";
} catch (Exception $e) {
    echo "fallback:", get_class($e), "\n";
}

try {
    throw new A();
} catch (Countable&Traversable&Throwable $e) {
    echo "triple\n";
} catch (Countable&Throwable $e) {
    echo "double:", get_class($e), "\n";
}

try {
    throw new A();
} catch (Countable&Throwable) {
    echo "noncapturing\n";
}
--EXPECT--
caught:A
fallback:B
double:A
noncapturing
