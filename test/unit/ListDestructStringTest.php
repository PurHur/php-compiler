<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** list() / [] destructuring from string RHS must TypeError (#7461). */
final class ListDestructStringTest extends TestCase
{
    public function testVmThrowsTypeErrorForStringRhs(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
try {
    [$a] = 'ab';
    echo 'no-exception';
} catch (TypeError $e) {
    echo $e->getMessage();
}
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'list_destructure_string.php'));
        self::assertSame('Cannot use string as array', ob_get_clean());
    }
}
