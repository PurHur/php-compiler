<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #4938 — yield / :Generator in static methods */
final class GeneratorStaticMethodCompileTest extends TestCase
{
    /**
     * @dataProvider staticGeneratorProvider
     */
    public function testStaticGeneratorFailsAtCompileTime(string $code): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Generator return type is not compatible with static function');
        $runtime->parseAndCompile($code, 'static_gen.php');
    }

    /** @return iterable<string, array{string}> */
    public static function staticGeneratorProvider(): iterable
    {
        yield 'typed yield' => [<<<'PHP'
<?php
class C {
    public static function gen(): Generator {
        yield 1;
    }
}
PHP];
        yield 'untyped yield' => [<<<'PHP'
<?php
class C {
    public static function gen() {
        yield 1;
    }
}
PHP];
        yield 'yield from' => [<<<'PHP'
<?php
class C {
    public static function gen(): Generator {
        yield from [1];
    }
}
PHP];
    }

    public function testInstanceGeneratorStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
class C {
    public function gen(): Generator {
        yield 1;
    }
}
foreach ((new C())->gen() as $v) {
    echo $v;
}
PHP, 'instance_gen.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame('1', ob_get_clean());
    }

    public function testStaticNonGeneratorStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
class C {
    public static function ok(): int {
        return 1;
    }
}
echo C::ok();
PHP, 'static_ok.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame('1', ob_get_clean());
    }
}
