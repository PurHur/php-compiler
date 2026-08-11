<?php
declare(strict_types=1);

// #30230: profile-gated by-ref Error wording (cannot ≤8.3 / could not ≥8.4 / default).
$raw = getenv('PHP_COMPILER_PROFILE');
if (is_string($raw) && $raw !== '') {
    $profile = preg_match('/^\d+\.\d+$/', trim($raw)) ? trim($raw) . '.0' : trim($raw);
    $verb = version_compare($profile, '8.4.0', '>=') ? 'could not' : 'cannot';
} else {
    $verb = 'could not'; // default 8.4.0-dev / #29624
}
$expected = $verb . ' be passed by reference';

foreach (['array_shift', 'array_pop', 'array_unshift'] as $fn) {
    try {
        if ($fn === 'array_unshift') {
            $fn([1, 2], 0);
        } else {
            $fn([1, 2]);
        }
        echo "$fn: no throw\n";
    } catch (Throwable $e) {
        if (!str_contains($e->getMessage(), $expected)) {
            echo "$fn: BAD wording (want '$expected'): ", $e->getMessage(), "\n";
            exit(1);
        }
        echo $fn, ': ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
