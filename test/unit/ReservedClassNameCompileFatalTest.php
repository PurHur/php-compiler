<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #32206 — reserved type names are Zend compile-time fatals as class-likes */
final class ReservedClassNameCompileFatalTest extends TestCase
{
    /**
     * @dataProvider illegalReservedClassLikeProvider
     */
    public function testReservedClassLikeFailsAtCompileTime(string $code, string $reserved): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage("Cannot use '{$reserved}' as class name as it is reserved");
        $runtime->parseAndCompile($code, 'reserved_class_name.php');
    }

    /** @return iterable<string, array{string, string}> */
    public static function illegalReservedClassLikeProvider(): iterable
    {
        yield 'class true' => ['<?php class true {} echo "accepted\n";', 'true'];
        yield 'class false' => ['<?php class false {} echo "accepted\n";', 'false'];
        yield 'class null' => ['<?php class null {} echo "accepted\n";', 'null'];
        yield 'class mixed' => ['<?php class mixed {} echo "accepted\n";', 'mixed'];
        yield 'class never' => ['<?php class never {} echo "accepted\n";', 'never'];
        yield 'class void' => ['<?php class void {} echo "accepted\n";', 'void'];
        yield 'class iterable' => ['<?php class iterable {} echo "accepted\n";', 'iterable'];
        yield 'class bool' => ['<?php class bool {} echo "accepted\n";', 'bool'];
        yield 'class int' => ['<?php class int {} echo "accepted\n";', 'int'];
        yield 'class float' => ['<?php class float {} echo "accepted\n";', 'float'];
        yield 'class string' => ['<?php class string {} echo "accepted\n";', 'string'];
        yield 'class object' => ['<?php class object {} echo "accepted\n";', 'object'];
        yield 'class TRUE case' => ['<?php class TRUE {} echo "accepted\n";', 'TRUE'];
        yield 'interface true' => ['<?php interface true {} echo "accepted\n";', 'true'];
        yield 'trait never' => ['<?php trait never {} echo "accepted\n";', 'never'];
        yield 'enum mixed' => ['<?php enum mixed {} echo "accepted\n";', 'mixed'];
        yield 'namespaced class true' => ['<?php namespace Foo; class true {} echo "accepted\n";', 'true'];
    }

    public function testLegalClassStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            '<?php class Truth {} echo "ok";',
            'reserved_class_ok.php'
        );
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame('ok', ob_get_clean());
    }
}
