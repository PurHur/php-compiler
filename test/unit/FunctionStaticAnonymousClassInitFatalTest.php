<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #15873 — function static init with anonymous class compile-time fatal */
final class FunctionStaticAnonymousClassInitFatalTest extends TestCase
{
    /**
     * @dataProvider invalidStaticAnonymousInitProvider
     */
    public function testInvalidStaticAnonymousInitFailsAtCompileTime(string $code): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot use anonymous class in constant expression');
        $runtime->parseAndCompile($code, 'static_anonymous_class_init.php');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidStaticAnonymousInitProvider(): iterable
    {
        yield 'named function' => ['<?php function f() { static $x = new class {}; }'];
        yield 'closure' => ['<?php function() { static $x = new class {}; };'];
        yield 'readonly anonymous class' => ['<?php function f() { static $x = new readonly class {}; }'];
    }

    public function testLegalStaticNamedClassInitStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            '<?php function holder() { static $obj = new stdClass; return $obj; }',
            'static_named_object_ok.php'
        );
        $this->assertNotNull($block);
    }
}
