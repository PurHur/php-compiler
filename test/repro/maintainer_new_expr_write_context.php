<?php
/**
 * Maintainer repro for #6691 — new expression in write context must compile-time fatal.
 *
 * Zend PHP 8.x compile-time fatals (exit 255); read contexts like (new C)->m() still run.
 */

declare(strict_types=1);

$fail = 0;

$illegal = [
    '(new stdClass())->x = 1;',
    'list((new stdClass())->a) = [1];',
    '(new stdClass())[0] = 1;',
];

foreach ($illegal as $code) {
    $wrapped = 'try { ' . $code . ' echo "ok\n"; } catch (Throwable $e) { echo get_class($e), ": ", $e->getMessage(), "\n"; }';
    exec('php bin/vm.php -r ' . escapeshellarg($wrapped), $lines, $exitCode);
    $out = implode("\n", $lines);
    $lines = [];
    if (255 !== $exitCode || str_contains($out, 'ok')) {
        fwrite(STDERR, "FAIL: VM should compile-error for: {$code}\noutput: {$out}\nexit: {$exitCode}\n");
        ++$fail;
    }
}

exec('php bin/vm.php -r ' . escapeshellarg('class C { public function m(): string { return "ok"; } } echo (new C())->m();'), $readLines, $readExit);
$readOut = implode('', $readLines);
if (0 !== $readExit || 'ok' !== rtrim($readOut)) {
    fwrite(STDERR, "FAIL: read context (new C)->m() expected ok, got exit {$readExit} output: {$readOut}\n");
    ++$fail;
}

exit($fail === 0 ? 0 : 1);
