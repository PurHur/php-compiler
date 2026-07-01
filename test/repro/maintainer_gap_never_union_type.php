<?php
/**
 * Maintainer repro for #14334 — never in union/intersection signatures compile-fatal.
 *
 * Zend: "never can only be used as a standalone type" (zend_handle_never_type).
 */

declare(strict_types=1);

$fail = 0;

$illegal = [
    'function f(): string|never { throw new Exception("x"); }',
    'function g(): int|never { throw new Exception("x"); }',
    'function h(int|never $x): int { return $x; }',
    'function i(never&string $x): void {}',
    'class C { public string|never $p; }',
];

foreach ($illegal as $code) {
    $wrapped = '<?php ' . $code;
    $tmp = tempnam(sys_get_temp_dir(), 'phpc14334_');
    if (false === $tmp) {
        fwrite(STDERR, "FAIL: tempnam\n");
        exit(1);
    }
    file_put_contents($tmp, $wrapped);
    exec('php bin/vm.php ' . escapeshellarg($tmp) . ' 2>&1', $lines, $exitCode);
    @unlink($tmp);
    $out = implode("\n", $lines);
    $lines = [];
    if (255 !== $exitCode || !str_contains($out, 'never can only be used as a standalone type')) {
        fwrite(STDERR, "FAIL: compile-error expected for: {$code}\noutput: {$out}\nexit: {$exitCode}\n");
        ++$fail;
    }
}

$valid = 'function f(): never { throw new Exception("x"); } try { f(); } catch (Exception $e) { echo $e->getMessage(); }';
$tmp = tempnam(sys_get_temp_dir(), 'phpc14334ok_');
file_put_contents($tmp, '<?php ' . $valid);
exec('php bin/vm.php ' . escapeshellarg($tmp) . ' 2>/dev/null', $okLines, $okExit);
@unlink($tmp);
$okOut = implode('', $okLines);
if (0 !== $okExit || 'x' !== rtrim($okOut)) {
    fwrite(STDERR, "FAIL: standalone never expected x, got exit {$okExit} output: {$okOut}\n");
    ++$fail;
}

exit(0 === $fail ? 0 : 1);
