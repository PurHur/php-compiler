<?php
/**
 * #28623 — AOT try/finally without local catch must propagate to outer catch.
 *
 * Root cause: finally epilogue uncaught path called libc abort() when no
 * same-function outer handler existed. Non-main callees must return with
 * throw-pending so the caller's emitCheckPendingThrowAfterCall can catch.
 *
 * @differential-repeat: 10
 */
function f() {
    try {
        throw new Exception('e');
    } finally {
        echo "F\n";
    }
}
try {
    f();
} catch (Exception $e) {
    echo "C\n";
}
