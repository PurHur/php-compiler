<?php
/**
 * #25395 — array_combine() runtime: ValueError on mismatch, never false (ext/standard/array.c).
 */
declare(strict_types=1);

try {
    array_combine([1, 2], [1]);
    echo "mismatch=none\n";
} catch (ValueError $e) {
    echo "mismatch=ValueError\n";
}

$ok = array_combine(['a', 'b'], [1, 2]);
echo 'ok=', $ok['a'], '|', $ok['b'], "\n";
