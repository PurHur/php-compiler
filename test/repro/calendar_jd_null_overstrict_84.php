<?php
/**
 * #24864 — calendar JD builtins soft-null under PROFILE=8.4 (Zend DEP+coerce).
 */
error_reporting(E_ALL);
$deps = [];
set_error_handler(static function (int $no, string $msg) use (&$deps): bool {
    if ($no === E_DEPRECATED) {
        $deps[] = $msg;
    }

    return true;
});

foreach ([
    'cal_from_jd' => static fn () => cal_from_jd(null, CAL_GREGORIAN),
    'jdtogregorian' => static fn () => jdtogregorian(null),
    'jdtojulian' => static fn () => jdtojulian(null),
    'jdtounix' => static fn () => jdtounix(null),
    'cal_to_jd' => static fn () => cal_to_jd(CAL_GREGORIAN, null, 1, 1),
] as $label => $fn) {
    $deps = [];
    try {
        $r = $fn();
        $out = is_array($r) ? ('ARR month=' . ($r['month'] ?? '?')) : var_export($r, true);
        $dep = $deps[0] ?? 'NO_DEP';
        echo $label, '=', $out, ' | ', $dep, "\n";
    } catch (Throwable $e) {
        $dep = $deps[0] ?? 'NO_DEP';
        echo $label, '=', get_class($e), ': ', $e->getMessage(), ' | ', $dep, "\n";
    }
}
