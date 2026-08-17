<?php

class C {
    private function priv(): int {
        return 42;
    }

    public function f(): void {
        // Control: self::priv() should work
        echo "self::priv() = " . self::priv() . "\n";

        // Bug: string callable 'C::priv' should throw Error
        try {
            $cb = 'C::priv';
            $result = $cb();
            echo "BUG: string callable returned $result\n";
        } catch (\Error $e) {
            echo "OK string: " . $e->getMessage() . "\n";
        }

        // Bug: array callable ['C','priv'] should throw Error
        try {
            $cb2 = ['C', 'priv'];
            $result2 = $cb2();
            echo "BUG: array callable returned $result2\n";
        } catch (\Error $e) {
            echo "OK array: " . $e->getMessage() . "\n";
        }
    }
}

$c = new C();
$c->f();
