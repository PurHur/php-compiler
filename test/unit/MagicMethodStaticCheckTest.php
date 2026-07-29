<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #25026 */
final class MagicMethodStaticCheckTest extends TestCase
{
    /**
     * @dataProvider staticMagicProvider
     */
    public function testStaticMagicMethodFailsAtCompileTime(string $code, string $message): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage($message);
        $runtime->parseAndCompile($code, 'invalid_static_magic.php');
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function staticMagicProvider(): iterable
    {
        yield '__sleep' => [
            <<<'PHP'
<?php
class Sl { static function __sleep() { return []; } }
PHP,
            'Method Sl::__sleep() cannot be static',
        ];
        yield '__wakeup' => [
            <<<'PHP'
<?php
class W { static function __wakeup() {} }
PHP,
            'Method W::__wakeup() cannot be static',
        ];
        yield '__invoke' => [
            <<<'PHP'
<?php
class I { static function __invoke() { return 1; } }
PHP,
            'Method I::__invoke() cannot be static',
        ];
        yield 'namespaced __sleep' => [
            <<<'PHP'
<?php
namespace N;
class Sl { public static function __sleep() { return []; } }
PHP,
            'Method N\\Sl::__sleep() cannot be static',
        ];
    }

    public function testNonStaticMagicMethodsStillCompileAndRun(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Ok {
    public function __sleep() { return []; }
    public function __wakeup() {}
    public function __invoke() { return 7; }
}
$o = new Ok();
echo $o(), "\n";
echo serialize($o), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'valid_static_magic.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("7\nO:2:\"Ok\":0:{}\n", ob_get_clean());
    }
}
