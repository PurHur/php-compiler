--TEST--
AOT: catch-arm type matching — multi-catch + single-arm type check (#34597)
--FILE--
<?php
// Single arm must not catch non-matching types (old $singleArm bypass).
try {
    throw new Error("e");
} catch (Exception $e) {
    echo "wrong-single\n";
} catch (Error $e) {
    echo "ok-error\n";
}

// Multi-catch: first arm matches.
try {
    throw new Exception("x");
} catch (Exception $e) {
    echo "ok-ex\n";
} catch (Error $e) {
    echo "wrong-ex\n";
}

// Multi-catch: parent catch matches subclass.
try {
    throw new RuntimeException("r");
} catch (Exception $e) {
    echo "ok-parent\n";
} catch (RuntimeException $e) {
    echo "wrong-parent\n";
}

// Multi-catch: first arm misses, second matches.
try {
    throw new Error("e2");
} catch (Exception $e) {
    echo "wrong-second\n";
} catch (Error $e) {
    echo "ok-second\n";
}
--EXPECT--
ok-error
ok-ex
ok-parent
ok-second
