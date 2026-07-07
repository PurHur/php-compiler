<?php

declare(strict_types=1);

// Maintainer gap #17132 — mb_trim/ltrim/rtrim(null) must TypeError on PHP 8.4 profile.

$fail = [];

foreach (['mb_trim', 'mb_ltrim', 'mb_rtrim'] as $fn) {
    if (!function_exists($fn)) {
        $fail[] = $fn . ': not registered';
        continue;
    }
    try {
        $fn(null);
        $fail[] = $fn . ': no TypeError';
    } catch (TypeError $e) {
        $expected = $fn . '(): Argument #1 ($string) must be of type string, null given';
        if ($expected !== $e->getMessage()) {
            $fail[] = $fn . ': wrong message: ' . $e->getMessage();
        }
    } catch (Throwable $e) {
        $fail[] = $fn . ': ' . $e::class;
    }
}

if ([] !== $fail) {
    echo 'fail: ' . implode('; ', $fail) . "\n";
    exit(1);
}

echo "ok\n";
