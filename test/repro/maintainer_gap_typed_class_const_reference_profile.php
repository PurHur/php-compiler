<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use PHPCompiler\CompilerVersion;
use PHPCompiler\Runtime;

if (CompilerVersion::supportsTypedClassConstants()) {
    echo "skip: typed class constants enabled on forward profile\n";
    exit(0);
}

$code = <<<'PHP'
<?php
class C {
    public const string K = 'v';
}
echo C::K;
PHP;

$runtime = new Runtime();
try {
    $runtime->parseAndCompile($code, 'typed_class_const_ref.php');
    echo "fail: typed class constant compiled on reference profile\n";
    exit(1);
} catch (CompileError $e) {
    echo "ok\n";
}
