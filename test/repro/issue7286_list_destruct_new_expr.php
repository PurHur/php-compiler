<?php
/**
 * Maintainer repro for #7286 — list/[] destructuring write slots on `new` offsets compile-fatal.
 *
 * Zend: "Assignments can only happen to writable values" (zend_compile.c list assign).
 * Reading from (new C())->a as RHS is valid and must still compile.
 */

declare(strict_types=1);

$fail = 0;

$illegal = [
    'class C { public array $a = [0]; } [(new C())->a[0]] = [1];',
    'class C { public array $a = [0]; } [(new C())->a] = [1];',
    'class C { public array $a = ["k" => 0]; } [(new C())->a["k"]] = [1];',
    'class C { public array $a = [0, 1]; } [(new C())->a[0], ...$t] = [1, 2, 3];',
    'class C { public array $a = [0, 1]; } [$a, (new C())->a[1]] = [1, 2];',
    'list((new stdClass())->a) = [1];',
    'class C { public array $a = [0]; } [[(new C())->a[0]]] = [[1]];',
    'class C { public array $a = [0]; } ["k" => (new C())->a[0]] = ["k" => 1];',
];

foreach ($illegal as $code) {
    $wrapped = '<?php ' . $code;
    $tmp = tempnam(sys_get_temp_dir(), 'phpc7286_');
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

$valid = 'class C { public array $a = [0]; } [$x] = (new C())->a; echo $x, "\n";';
$tmp = tempnam(sys_get_temp_dir(), 'phpc7286ok_');
file_put_contents($tmp, '<?php ' . $valid);
exec('php bin/vm.php ' . escapeshellarg($tmp) . ' 2>&1', $okLines, $okExit);
@unlink($tmp);
$okOut = implode('', $okLines);
if (0 !== $okExit || '0' !== rtrim($okOut)) {
    fwrite(STDERR, "FAIL: valid destructuring from (new C())->a expected 0, got exit {$okExit} output: {$okOut}\n");
    ++$fail;
}

exit(0 === $fail ? 0 : 1);
