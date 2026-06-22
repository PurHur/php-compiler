--TEST--
language: arrow fn $this outside object context throws Error (issue #10558)
--FILE--
<?php
try {
    (fn() => $this)();
    echo "no error\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}

class C {
    public function test(): void {
        $r = (fn() => $this)();
        echo is_object($r) ? get_class($r) : var_export($r, true), "\n";
    }
}
(new C())->test();
--EXPECT--
Using $this when not in object context
C
