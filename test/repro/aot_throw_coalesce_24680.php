<?php
function f(?int $x): int {
    return $x ?? throw new Exception("e");
}
try {
    f(null);
    echo "bad";
} catch (Throwable $e) {
    echo "c";
}
echo f(2);
