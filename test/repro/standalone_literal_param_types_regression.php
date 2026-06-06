<?php
function f(true $x) { return $x; }
function g(false $x) { return $x; }
function h(null $x) { return 'ok'; }

foreach ([['f', 1], ['g', 0], ['h', 1]] as [$fn, $arg]) {
    try {
        $fn($arg);
        echo "$fn($arg): accepted\n";
    } catch (Throwable $e) {
        echo "$fn($arg): ", get_class($e), "\n";
    }
}
