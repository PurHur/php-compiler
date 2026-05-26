<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** use function / use const imports (issue #2325). */
final class UseFunctionImportTest extends TestCase
{
    public function testVmUseFunctionAndUseConst(): void
    {
        $code = <<<'PHP'
<?php
namespace N {
    const ANSWER = 42;
    function greet(): string {
        return 'hi';
    }
}
namespace User {
    use const N\ANSWER;
    use function N\greet;
    echo greet(), ' ', ANSWER;
}
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'use_import.php');
        ob_start();
        $rt->run($block);
        $this->assertSame('hi 42', ob_get_clean());
    }
}
