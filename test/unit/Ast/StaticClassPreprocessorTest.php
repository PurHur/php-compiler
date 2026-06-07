<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit\Ast;

use PHPCompiler\Ast\StaticClassPreprocessor;
use PHPUnit\Framework\TestCase;

/**
 * @see StaticClassPreprocessor (#6929)
 */
final class StaticClassPreprocessorTest extends TestCase
{
    public function testStripsStaticClassModifierAndRecordsLine(): void
    {
        $code = <<<'PHP'
<?php
static class S {
    public static function m(): void {}
}
PHP;
        $pp = new StaticClassPreprocessor();
        [$stripped, $map] = $pp->preprocess($code);
        self::assertStringNotContainsString('static class', $stripped);
        self::assertStringContainsString('class S', $stripped);
        self::assertArrayHasKey(2, $map);
        self::assertTrue($map[2]);
    }

    public function testDoesNotStripStaticOnMethods(): void
    {
        $code = <<<'PHP'
<?php
class C {
    public static function m(): void {}
}
PHP;
        $pp = new StaticClassPreprocessor();
        [$stripped, $map] = $pp->preprocess($code);
        self::assertSame($code, $stripped);
        self::assertSame([], $map);
    }
}
