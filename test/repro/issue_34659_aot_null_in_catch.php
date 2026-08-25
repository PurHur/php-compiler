<?php
// #34659: null literal inside catch must not SIGSEGV under AOT.
try {
    throw new Exception('x');
} catch (Throwable $e) {
    $x = null;
    echo ($x === null ? 'assign=Y' : 'assign=N'), "\n";
    echo (null === null ? 'cmp=Y' : 'cmp=N'), "\n";
    echo null, "done\n";
}
