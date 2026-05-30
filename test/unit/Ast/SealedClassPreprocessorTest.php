<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit\Ast;

use PHPCompiler\Ast\SealedClassPreprocessor;
use PHPUnit\Framework\TestCase;

/**
 * @see SealedClassPreprocessor (#3322)
 */
final class SealedClassPreprocessorTest extends TestCase
{
    public function testStripsSealedAndRecordsLine(): void
    {
        $code = <<<'PHP'
<?php
sealed class C permits D, E extends Base {
}
PHP;
        $pp = new SealedClassPreprocessor();
        [$stripped, $map] = $pp->preprocess($code);
        self::assertStringNotContainsString('sealed', $stripped);
        self::assertStringNotContainsString('permits', $stripped);
        self::assertStringContainsString('class C', $stripped);
        self::assertArrayHasKey(2, $map);
        self::assertSame(['d', 'e'], $map[2]);
    }
}
