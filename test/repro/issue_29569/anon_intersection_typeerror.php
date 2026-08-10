<?php
// #29569 — intersection TypeError for anonymous class must use Zend display name (no NUL/path).
error_reporting(E_ALL);
interface A {}
interface B {}
function f(A&B $x): void {}
try {
    f(new class implements A {});
    echo "UNEXPECTED_OK\n";
} catch (Throwable $e) {
    $m = $e->getMessage();
    echo str_contains($m, "\0") ? "HAS_NUL\n" : "NO_NUL\n";
    echo $m, "\n";
}

// Named control — must stay green.
class OnlyA implements A {}
try {
    f(new OnlyA());
    echo "UNEXPECTED_NAMED_OK\n";
} catch (Throwable $e) {
    echo str_contains($e->getMessage(), "\0") ? "NAMED_HAS_NUL\n" : "NAMED_OK\n";
    echo $e->getMessage(), "\n";
}
