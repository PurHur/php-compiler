<?php
/**
 * Maintainer repro for #14325 — list/[] destructuring slots with default values compile-fatal.
 *
 * Zend: "Assignments can only happen to writable values" (zend_compile_list_assign).
 */

declare(strict_types=1);

$fail = 0;

$illegal = [
    '[$a = 1] = [2];',
    'list($a = 1) = [2];',
    'foreach ([[1, 2]] as [$a = 0, $b]) { echo $a . $b; }',
];

foreach ($illegal as $code) {
    $wrapped = '<?php ' . $code;
    $tmp = tempnam(sys_get_temp_dir(), 'phpc14325_');
    if (false === $tmp) {
        fwrite(STDERR, "FAIL: tempnam\n");
        exit(1);
    }
    file_put_contents($tmp, $wrapped);
    exec('php bin/vm.php ' . escapeshellarg($tmp) . ' 2>&1', $lines, $exitCode);
    @unlink($tmp);
    $out = implode("\n", $lines);
    $lines = [];
    if (255 !== $exitCode || !str_contains($out, 'Assignments can only happen to writable values')) {
        fwrite(STDERR, "FAIL: compile-error expected for: {$code}\noutput: {$out}\nexit: {$exitCode}\n");
        ++$fail;
    }
}

$valid = '[$a, $b] = [1, 2]; echo $a + $b, "\n";';
$tmp = tempnam(sys_get_temp_dir(), 'phpc14325ok_');
file_put_contents($tmp, '<?php ' . $valid);
exec('php bin/vm.php ' . escapeshellarg($tmp) . ' 2>/dev/null', $okLines, $okExit);
@unlink($tmp);
$okOut = implode('', $okLines);
if (0 !== $okExit || '3' !== rtrim($okOut)) {
    fwrite(STDERR, "FAIL: valid destructuring expected 3, got exit {$okExit} output: {$okOut}\n");
    ++$fail;
}

exit(0 === $fail ? 0 : 1);
