<?php
// #34659: null literal inside catch must not SIGSEGV under AOT.
try {
    throw new Exception('x');
} catch (Throwable $e) {
    $x = null;
    echo ($x === null ? 'Y' : 'N');
    echo (null === null ? 'Y' : 'N');
    echo null;
    echo "ok\n";
}
