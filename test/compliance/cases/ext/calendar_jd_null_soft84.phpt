--TEST--
ext calendar JD null soft-null under PROFILE=8.4 (VM, issue #24864)
--FILE--
<?php
error_reporting(E_ALL);
$deps = [];
set_error_handler(static function (int $no, string $msg) use (&$deps): bool {
    if ($no === E_DEPRECATED) {
        $deps[] = $msg;
    }
    return true;
});

$cases = [
    'cal_from_jd' => static fn () => cal_from_jd(null, CAL_GREGORIAN),
    'jdtogregorian' => static fn () => jdtogregorian(null),
    'jdtojulian' => static fn () => jdtojulian(null),
    'jdtounix' => static fn () => jdtounix(null),
    'cal_to_jd' => static fn () => cal_to_jd(CAL_GREGORIAN, null, 1, 1),
];
foreach ($cases as $label => $fn) {
    $deps = [];
    try {
        $r = $fn();
        $out = is_array($r) ? ('ARR:' . ($r['month'] ?? '?')) : (string) $r;
        $ok = isset($deps[0]) && str_contains($deps[0], 'Passing null to parameter');
        echo $label, '=', $out, ' dep=', $ok ? 'yes' : 'no', PHP_EOL;
    } catch (Throwable $e) {
        $ok = isset($deps[0]) && str_contains($deps[0], 'Passing null to parameter');
        echo $label, '=', get_class($e), ' dep=', $ok ? 'yes' : 'no', PHP_EOL;
    }
}
?>
--EXPECT--
cal_from_jd=ARR:0 dep=yes
jdtogregorian=0/0/0 dep=yes
jdtojulian=0/0/0 dep=yes
jdtounix=ValueError dep=yes
cal_to_jd=0 dep=yes
