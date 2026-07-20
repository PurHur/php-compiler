<?php
// #21479 — null $pattern soft-null under PROFILE=8.4; preg_replace already soft (#21198)
error_reporting(E_ALL);
set_error_handler(static function (int $n, string $m): bool {
    return true;
});
foreach ([
    fn () => preg_match(null, 'x'),
    fn () => preg_split(null, 'x'),
    fn () => preg_grep(null, ['x']),
    fn () => preg_replace(null, 'b', 'a'),
] as $fn) {
    try {
        $r = $fn();
        echo is_bool($r) && false === $r ? "false\n" : (null === $r ? "NULL\n" : "COERCE\n");
    } catch (Throwable $e) {
        echo get_class($e), "\n";
    }
}
