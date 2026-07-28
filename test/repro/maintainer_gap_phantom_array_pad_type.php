<?php
/**
 * #24002 — ArrayPadType / ARRAY_PAD_* / array_pad() 4th arg are phantoms.
 * php-src: array_pad(array $array, int $length, mixed $value): array only.
 *
 * Expect under PHP_COMPILER_PROFILE=8.4:
 *   class_exists('ArrayPadType') → false
 *   defined('ARRAY_PAD_LEFT') → false
 *   ReflectionFunction('array_pad')->getNumberOfParameters() → 3
 *   sign-of-length padding still works
 */
declare(strict_types=1);

$fail = static function (string $msg): void {
    fwrite(STDERR, "fail: {$msg}\n");
    exit(1);
};

if (class_exists('ArrayPadType', false) || enum_exists('ArrayPadType', false)) {
    $fail('ArrayPadType must not exist (php-src never ships it)');
}
foreach (['ARRAY_PAD_LEFT', 'ARRAY_PAD_RIGHT', 'ARRAY_PAD_BOTH'] as $c) {
    if (defined($c)) {
        $fail("{$c} must not be defined");
    }
}

$rf = new ReflectionFunction('array_pad');
if (3 !== $rf->getNumberOfParameters()) {
    $fail('array_pad Reflection arity expected 3, got '.$rf->getNumberOfParameters());
}

try {
    array_pad([1], 3, 0, 0);
    $fail('4th positional arg must raise ArgumentCountError');
} catch (ArgumentCountError $e) {
    // ok
}

try {
    array_pad([1], 3, 0, pad_type: 0);
    $fail('named pad_type must be rejected');
} catch (Throwable $e) {
    // Error / ArgumentCountError / unknown named parameter — any rejection is fine
}

$right = array_pad([1, 2], 5, 0);
$left = array_pad([1, 2], -5, 0);
if ($right !== [1, 2, 0, 0, 0]) {
    $fail('positive length pad mismatch: '.var_export($right, true));
}
if ($left !== [0, 0, 0, 1, 2]) {
    $fail('negative length pad mismatch: '.var_export($left, true));
}

echo "ok\n";
