<?php
/**
 * AOT probe #23919 — named str_shuffle(string:) must compile (not Unknown named parameter).
 * ReflectionFunction may be unavailable under AOT; shuffle is non-deterministic.
 */
$sig = static function (string $s): string {
    $parts = str_split($s);
    sort($parts);

    return implode('', $parts);
};
try {
    $named = str_shuffle(string: 'ab');
    echo 2 === strlen($named) && $sig('ab') === $sig($named) ? "named_ok\n" : "named_bad\n";
} catch (Throwable $e) {
    echo str_starts_with($e->getMessage(), 'Unknown named parameter')
        ? "named_rejected\n"
        : ('named_other=' . get_class($e) . "\n");
}
$pos = str_shuffle('ab');
echo 2 === strlen($pos) && $sig('ab') === $sig($pos) ? "pos_ok\n" : "pos_bad\n";
