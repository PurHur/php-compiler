<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit\Ast;

use PHPCompiler\Ast\StaticClassPreprocessor;
use PHPCompiler\Compiler\CompileFatal;
use PHPUnit\Framework\TestCase;

/**
 * @see StaticClassPreprocessor (#6929, #24894)
 */
final class StaticClassPreprocessorTest extends TestCase
{
    private ?string $prevProfile = null;

    protected function setUp(): void
    {
        $raw = getenv('PHP_COMPILER_PROFILE');
        $this->prevProfile = false === $raw ? null : $raw;
    }

    protected function tearDown(): void
    {
        if (null === $this->prevProfile) {
            putenv('PHP_COMPILER_PROFILE');
        } else {
            putenv('PHP_COMPILER_PROFILE='.$this->prevProfile);
        }
    }

    public function testStripsStaticClassModifierAndRecordsLineOnForwardProfile84(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.4');
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
        putenv('PHP_COMPILER_PROFILE=8.4');
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

    /** @covers issue #24894 */
    public function testRejectsStaticClassOnReferenceProfile(): void
    {
        putenv('PHP_COMPILER_PROFILE');
        $code = <<<'PHP'
<?php
static class S {
    public static function m(): void {}
}
PHP;
        $pp = new StaticClassPreprocessor();
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(StaticClassPreprocessor::PARSE_MESSAGE);
        $pp->preprocess($code, 'static_class.php');
    }

    /** @covers issue #24894 */
    public function testRejectsStaticClassOnPhp82Profile(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.2');
        $code = <<<'PHP'
<?php
static class A { public static function f(){ return 1; } }
PHP;
        $pp = new StaticClassPreprocessor();
        try {
            $pp->preprocess($code, 'repro.php');
            self::fail('expected CompileFatal');
        } catch (CompileFatal $e) {
            self::assertSame(StaticClassPreprocessor::PARSE_MESSAGE, $e->getMessage());
            self::assertSame(2, $e->sourceLine);
            self::assertStringContainsString('Parse error:', $e->zendStderrLine());
        }
    }
}
