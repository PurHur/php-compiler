<?php
class S {
    public function __toString(): string { return 'x'; }
}
function f(string|Stringable $s): void {}
try {
    f(new S());
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
