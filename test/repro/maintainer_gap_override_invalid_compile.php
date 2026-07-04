<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use PHPCompiler\CompilerVersion;
use PHPCompiler\Runtime;

if (!CompilerVersion::supportsOverrideAttribute()) {
    echo "skip: Override validation disabled on reference profile\n";
    exit(0);
}

$source = <<<'PHP'
<?php

declare(strict_types=1);

class Base {}

class Child extends Base
{
    #[\Override]
    public function h(): void {}
}
PHP;

$runtime = new Runtime();
try {
    $runtime->parseAndCompile($source, 'override_invalid_h.php');
    fwrite(STDERR, "fail: compiled\n");
    exit(1);
} catch (CompileError $e) {
    if (!str_contains($e->getMessage(), '#[\Override]')) {
        fwrite(STDERR, 'fail: unexpected message: ' . $e->getMessage() . "\n");
        exit(1);
    }
}

echo "ok\n";
