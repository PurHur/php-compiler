<?php
// Repro #20226: null $pattern must TypeError under PHP_COMPILER_PROFILE=8.4.
foreach ([
    fn () => @preg_match(null, 'x'),
    fn () => @preg_split(null, 'x'),
    fn () => @preg_grep(null, ['x']),
    fn () => @preg_replace(null, 'b', 'a'),
] as $fn) {
    try {
        $fn();
        echo "COERCE\n";
    } catch (Throwable $e) {
        echo get_class($e), "\n";
    }
}
