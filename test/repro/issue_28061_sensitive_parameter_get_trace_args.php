<?php
// Repro #28061 — bare VM (no -d / ini_set) must keep getTrace args and wrap
// #[\SensitiveParameter] as SensitiveParameterValue (php-src compiled default Off;
// php:8.4-cli / `php -n`). Distro php.ini On must not leak into the guest.
function f(#[\SensitiveParameter] string $password, string $ok): void {
    throw new Exception('boom');
}
try {
    f('secret-value', 'visible');
} catch (Throwable $e) {
    $frame = $e->getTrace()[0] ?? [];
    echo 'has_args=', array_key_exists('args', $frame) ? 'Y' : 'N', "\n";
    if (isset($frame['args'])) {
        foreach ($frame['args'] as $i => $a) {
            echo 'arg'.$i.'=', is_object($a) ? get_class($a) : var_export($a, true), "\n";
        }
    }
    echo 'asString_has_secret=', str_contains($e->getTraceAsString(), 'secret-value') ? 'Y' : 'N', "\n";
    echo 'asString_wrapped=', str_contains($e->getTraceAsString(), 'Object(SensitiveParameterValue)') ? 'Y' : 'N', "\n";
}
