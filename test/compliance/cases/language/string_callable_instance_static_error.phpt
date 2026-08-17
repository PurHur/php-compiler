--TEST--
Language: 'C::instanceMethod'() from instance throws Error — cannot be called statically (#31915)
--FILE--
<?php
class C {
    private function priv(): int {
        return 42;
    }

    public function f(): void {
        echo "self=" . self::priv() . "\n";

        try {
            $cb = 'C::priv';
            $cb();
            echo "BUG-string\n";
        } catch (Error $e) {
            echo "string:" . $e->getMessage() . "\n";
        }

        try {
            $cb2 = ['C', 'priv'];
            $cb2();
            echo "BUG-array\n";
        } catch (Error $e) {
            echo "array:" . $e->getMessage() . "\n";
        }
    }
}

(new C())->f();
?>
--EXPECT--
self=42
string:Non-static method C::priv() cannot be called statically
array:Non-static method C::priv() cannot be called statically
