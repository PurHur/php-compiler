--TEST--
never return type: empty body compiles and raises TypeError at runtime (issue #4206)
--FILE--
<?php
function f(): never {
}
try {
    f();
    echo "after\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
f(): never-returning function must not implicitly return
