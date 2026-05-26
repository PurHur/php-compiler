<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #2499 */
final class UnionTypeParseTest extends TestCase
{
    public function testUnionReturnTypeParsesAndRuns(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function pick(bool $flag): int|string
{
    return $flag ? 1 : 'x';
}
echo pick(true), pick(false);
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'union_return.php'));
        $this->assertSame('1x', ob_get_clean());
    }
}
