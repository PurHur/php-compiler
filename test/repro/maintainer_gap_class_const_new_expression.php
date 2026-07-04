<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use PHPCompiler\Compiler\NewWithoutParensCompileCheck;
use PHPCompiler\Runtime;

$source = <<<'PHP'
<?php

declare(strict_types=1);

class Holder {
    public const OBJ = new \stdClass();
}

echo get_class(Holder::OBJ), "\n";
PHP;

$runtime = new Runtime();
try {
    $runtime->parseAndCompile($source, 'class_const_new.php');
    fwrite(STDERR, "fail: class const new stdClass() compiled under PHP_COMPILER_PROFILE="
        . (getenv('PHP_COMPILER_PROFILE') ?: 'default') . "\n");
    exit(1);
} catch (CompileError $e) {
    if (NewWithoutParensCompileCheck::MESSAGE !== $e->getMessage()) {
        fwrite(STDERR, 'fail: unexpected message: ' . $e->getMessage() . "\n");
        exit(1);
    }
}

echo "ok\n";
