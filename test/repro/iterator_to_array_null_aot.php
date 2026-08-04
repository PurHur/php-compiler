<?php
// #27634 — boxed null must TypeError under AOT (not Generator resume metadata).
try {
    $r = iterator_to_array(null);
    echo "NO_THROW_LIT:" . gettype($r), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
$x = null;
try {
    $r = iterator_to_array($x);
    echo "NO_THROW_VAR:" . gettype($r), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
echo implode(',', iterator_to_array(new ArrayIterator([1, 2]), false)), "\n";
