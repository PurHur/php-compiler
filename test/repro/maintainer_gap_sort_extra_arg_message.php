<?php
/**
 * #23855 — sort-family excess argc message matches php-src ArgumentCountError.
 * php-src: ext/standard/array.c / array.stub.php (arity 1–2).
 */
$fns = ['sort', 'rsort', 'asort', 'arsort', 'ksort', 'krsort'];
foreach ($fns as $fn) {
    try {
        $a = [1];
        $fn($a, SORT_REGULAR, 99);
        echo "{$fn}:uncaught\n";
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        if ($e instanceof ArgumentCountError
            && false !== strpos($msg, 'expects at most 2 arguments, 3 given')
        ) {
            echo "{$fn}:ok\n";
        } else {
            echo "{$fn}:fail ", get_class($e), ': ', $msg, "\n";
        }
    }
}
echo "ok\n";
