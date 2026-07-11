<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use PHPCompiler\Compiler\NewWithoutParensCompileCheck;
use PHPCompiler\CompilerVersion;
use PHPCompiler\Runtime;

$source = <<<'PHP'
<?php

declare(strict_types=1);

class Holder {
    public const DT = new DateTime('1970-01-01');
}

echo Holder::DT->format('Y-m-d'), "\n";
PHP;

$runtime = new Runtime();
if (!CompilerVersion::supportsClassConstObjectExpressions()) {
    try {
        $runtime->parseAndCompile($source, 'class_const_new.php');
        fwrite(STDERR, "fail: class const new compiled on reference profile\n");
        exit(1);
    } catch (CompileError $e) {
        if (NewWithoutParensCompileCheck::MESSAGE !== $e->getMessage()) {
            fwrite(STDERR, 'fail: unexpected message: ' . $e->getMessage() . "\n");
            exit(1);
        }
    }
    echo "ok\n";
    exit(0);
}

$block = $runtime->parseAndCompile($source, 'class_const_new.php');
if (null === $block) {
    fwrite(STDERR, "fail: parseAndCompile returned null\n");
    exit(1);
}
$runtime->run($block);
echo "ok\n";
