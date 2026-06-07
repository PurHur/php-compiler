<?php
trait T {
    private const X = 1;
    protected const Y = 2;
    public const Z = 3;
}
class C { use T; }

echo C::Z, "\n"; // ok
try {
    echo C::X;
    echo "BUG: private readable\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    echo C::Y;
    echo "BUG: protected readable\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
