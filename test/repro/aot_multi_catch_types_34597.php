<?php
// Repro #34597 — AOT catch-arm type matching
try {
    throw new Error("e");
} catch (Exception $e) {
    echo "wrong-single\n";
} catch (Error $e) {
    echo "ok-error\n";
}

try {
    throw new Exception("x");
} catch (Exception $e) {
    echo "ok-ex\n";
} catch (Error $e) {
    echo "wrong-ex\n";
}

try {
    throw new RuntimeException("r");
} catch (Exception $e) {
    echo "ok-parent\n";
} catch (RuntimeException $e) {
    echo "wrong-parent\n";
}

try {
    throw new Error("e2");
} catch (Exception $e) {
    echo "wrong-second\n";
} catch (Error $e) {
    echo "ok-second\n";
}
