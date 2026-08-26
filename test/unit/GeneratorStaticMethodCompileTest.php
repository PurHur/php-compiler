<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #35153 — static method generators match Zend (re-#4938) */
final class GeneratorStaticMethodCompileTest extends TestCase
{
    /**
     * @dataProvider staticGeneratorProvider
     */
    public function testStaticGeneratorCompilesAndRuns(string $code, string $expected): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'static_gen.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame($expected, ob_get_clean());
    }

    /** @return iterable<string, array{string, string}> */
    public static function staticGeneratorProvider(): iterable
    {
        yield 'typed yield' => [<<<'PHP'
<?php
class C {
    public static function gen(): Generator {
        yield 1;
        yield 2;
    }
}
foreach (C::gen() as $v) {
    echo $v;
}
PHP, '12'];
        yield 'untyped yield' => [<<<'PHP'
<?php
class C {
    public static function gen() {
        yield 1;
        yield 2;
    }
}
foreach (C::gen() as $v) {
    echo $v;
}
PHP, '12'];
        yield 'yield from' => [<<<'PHP'
<?php
class C {
    public static function gen(): Generator {
        yield from [1, 2];
    }
}
foreach (C::gen() as $v) {
    echo $v;
}
PHP, '12'];
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
