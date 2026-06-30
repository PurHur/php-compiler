<?php
declare(strict_types=1);

// Maintainer repro for #14062 — property hooks are PHP 8.4; Zend 8.2 reference must reject.
require __DIR__.'/../../vendor/autoload.php';

$runtime = new PHPCompiler\Runtime();
try {
    $runtime->parseAndCompile(
        file_get_contents(__DIR__.'/maintainer_gap_property_hooks_reference_profile.php'),
        'property_hooks_syntax_84.php'
    );
    fwrite(STDERR, "FAIL: parsed\n");
    exit(1);
} catch (PHPCompiler\Compiler\CompileFatal $e) {
    if (!str_contains($e->getMessage(), 'unexpected token "{", expecting "," or ";"')) {
        fwrite(STDERR, 'FAIL: wrong message: '.$e->getMessage()."\n");
        exit(1);
    }
    echo "OK: parse rejected\n";
    exit(0);
}
