<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #5478 — function static init with closure/arrow fn compile-time fatal */
final class FunctionStaticClosureInitFatalTest extends TestCase
{
    /**
     * @dataProvider invalidStaticInitProvider
     */
    public function testInvalidStaticInitFailsAtCompileTime(string $code): void
    {
        $runtime = new Runtime();
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Constant expression contains invalid operations');
        $runtime->parseAndCompile($code, 'static_closure_init.php');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidStaticInitProvider(): iterable
    {
        yield 'closure' => ['<?php function f() { static $c = function () { return 1; }; }'];
        yield 'arrow fn' => ['<?php function g() { static $a = fn () => 1; }'];
    }

    public function testLegalStaticObjectInitStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            '<?php function holder() { static $obj = new stdClass; return $obj; }',
            'static_object_ok.php'
        );
        $this->assertNotNull($block);
    }
}
