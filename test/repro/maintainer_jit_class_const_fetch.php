<?php
declare(strict_types=1);

/**
 * Maintainer repro for #6964 — JIT MCJIT must not segfault on class constant fetch.
 */

class C {
    public const X = 1;
}

$failures = 0;

$commands = [
    ['literal', 'class C { public const X=1; } echo C::X, "\\n";'],
    ['variable class', 'class C { public const X=1; } $cls="C"; echo $cls::X, "\\n";'],
    ['dynamic name', 'class C { const X=1; } $n="X"; echo C::{$n}, "\\n";'],
];

foreach ($commands as [$label, $snippet]) {
    $cmd = 'php bin/jit.php -r '.escapeshellarg($snippet).' 2>&1';
    $output = [];
    $exit = 0;
    exec($cmd, $output, $exit);
    if (139 === $exit || -11 === $exit) {
        echo "FAIL {$label}: segfault (exit {$exit})\n";
        ++$failures;
        continue;
    }
    if (0 !== $exit) {
        echo "FAIL {$label}: exit {$exit}: ".implode("\n", $output)."\n";
        ++$failures;
        continue;
    }
    $line = trim(preg_replace('/^PHP Warning:.*$/m', '', implode("\n", $output)));
    $line = trim($line);
    if ('1' !== $line) {
        echo "FAIL {$label}: expected 1, got {$line}\n";
        ++$failures;
        continue;
    }
    echo "OK {$label}\n";
}

exit($failures > 0 ? 1 : 0);
