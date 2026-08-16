<?php
/**
 * #28201 — PadType is a phantom vs php-src; str_pad() takes int $pad_type + STR_PAD_*.
 *
 * Expect under PHP_COMPILER_PROFILE=8.4:
 *   enum_exists('PadType') → false
 *   str_pad(..., STR_PAD_LEFT) still works
 */
declare(strict_types=1);

$fail = static function (string $msg): void {
    fwrite(STDERR, "fail: {$msg}\n");
    exit(1);
};

if (class_exists('PadType', false) || enum_exists('PadType', false)) {
    $fail('PadType must not exist (php-src never ships it)');
}

$left = str_pad('a', 5, ' ', STR_PAD_LEFT);
$right = str_pad('a', 5, ' ', STR_PAD_RIGHT);
$both = str_pad('a', 5, '-', STR_PAD_BOTH);
if ('    a' !== $left) {
    $fail('STR_PAD_LEFT mismatch: '.var_export($left, true));
}
if ('a    ' !== $right) {
    $fail('STR_PAD_RIGHT mismatch: '.var_export($right, true));
}
if ('--a--' !== $both) {
    $fail('STR_PAD_BOTH mismatch: '.var_export($both, true));
}

echo "ok\n";
