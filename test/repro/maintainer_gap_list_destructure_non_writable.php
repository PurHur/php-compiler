<?php
/**
 * Maintainer repro for #12498 — keyed list destructuring to non-writable targets compile-fatal.
 *
 * Zend: "Assignments can only happen to writable values" (zend_compile.c list assign).
 */

declare(strict_types=1);

$fail = 0;
$repoRoot = dirname(__DIR__, 2);
$vm = $repoRoot . '/bin/vm.php';

$illegal = [
    "['a' => 1] = ['a' => 1];",
    '[1] = [1];',
    'list(1) = [1];',
];

foreach ($illegal as $code) {
    $wrapped = '<?php declare(strict_types=1); ' . $code;
    $tmp = tempnam(sys_get_temp_dir(), 'phpc12498_');
    if (false === $tmp) {
        fwrite(STDERR, "FAIL: tempnam\n");
        exit(1);
    }
    file_put_contents($tmp, $wrapped);
    exec('php ' . escapeshellarg($vm) . ' ' . escapeshellarg($tmp) . ' 2>&1', $lines, $exitCode);
    @unlink($tmp);
    $out = implode("\n", $lines);
    $lines = [];
    if (255 !== $exitCode || !str_contains($out, 'Assignments can only happen to writable values')) {
        fwrite(STDERR, "FAIL: compile-error expected for: {$code}\noutput: {$out}\nexit: {$exitCode}\n");
        ++$fail;
    }
}

$valid = "['a' => \$x] = ['a' => 1]; echo \$x, \"\\n\";";
$tmp = tempnam(sys_get_temp_dir(), 'phpc12498ok_');
file_put_contents($tmp, '<?php declare(strict_types=1); ' . $valid);
exec('php ' . escapeshellarg($vm) . ' ' . escapeshellarg($tmp) . ' 2>&1', $okLines, $okExit);
@unlink($tmp);
$okOut = implode('', array_filter($okLines, static fn (string $line): bool => !str_starts_with($line, 'PHP Warning:')));
if (0 !== $okExit || '1' !== rtrim($okOut)) {
    fwrite(STDERR, "FAIL: valid keyed destructuring expected 1, got exit {$okExit} output: {$okOut}\n");
    ++$fail;
}

exit(0 === $fail ? 0 : 1);
