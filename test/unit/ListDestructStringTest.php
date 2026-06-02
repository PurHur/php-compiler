<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** list() / [] destructuring from string RHS must leave targets unset (#4308). */
final class ListDestructStringTest extends TestCase
{
    public function testVmLeavesTargetsUnsetForStringRhs(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
[$a] = 'ab';
echo $a === null ? 'null' : $a;
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'list_destructure_string.php'));
        self::assertSame('null', ob_get_clean());
    }
}
