<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Compiler\CompileFatal;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #32254 — use … as self/parent is a Zend compile-time fatal */
final class UseAsSpecialClassNameCompileFatalTest extends TestCase
{
    /**
     * @dataProvider illegalUseAliasProvider
     */
    public function testUseAsSpecialClassNameFailsAtCompileTime(string $code, string $needle): void
    {
        $runtime = new Runtime();
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage($needle);
        $runtime->parseAndCompile($code, 'use_as_special.php');
    }

    /** @return iterable<string, array{string, string}> */
    public static function illegalUseAliasProvider(): iterable
    {
        yield 'use as self' => [
            "<?php class Foo {} use Foo as self; echo \"accepted\\n\";",
            "Cannot use Foo as self because 'self' is a special class name",
        ];
        yield 'use as parent' => [
            "<?php class Foo {} use Foo as parent; echo \"accepted\\n\";",
            "Cannot use Foo as parent because 'parent' is a special class name",
        ];
        yield 'namespaced use as self' => [
            "<?php use Foo\\Bar as self; echo \"accepted\\n\";",
            "Cannot use Foo\\Bar as self because 'self' is a special class name",
        ];
        yield 'group use as parent' => [
            "<?php use Foo\\{Bar as parent}; echo \"accepted\\n\";",
            "Cannot use Bar as parent because 'parent' is a special class name",
        ];
        yield 'use as Self case' => [
            "<?php class Foo {} use Foo as Self; echo \"accepted\\n\";",
            "Cannot use Foo as Self because 'Self' is a special class name",
        ];
    }

    public function testLegalUseAliasStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            '<?php class Foo {} use Foo as FooAlias; echo "ok";',
            'use_as_ok.php'
        );
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame('ok', ob_get_clean());
    }

    public function testMapperStripsParserLineSuffix(): void
    {
        $this->assertSame(
            "Cannot use Foo as self because 'self' is a special class name",
            CompileFatal::useAsSpecialClassNameMessage(
                "Cannot use Foo as self because 'self' is a special class name on line 3"
            )
        );
    }

    public function testMapperDoesNotRemapStaticAlias(): void
    {
        $this->assertNull(
            CompileFatal::useAsSpecialClassNameMessage(
                "Cannot use Foo as static because 'static' is a special class name"
            )
        );
    }

    public function testRethrowMapsParserErrorWithoutLeakingClass(): void
    {
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage("Cannot use Foo as self because 'self' is a special class name");
        CompileFatal::rethrowUseAsSpecialClassName(
            new \PhpParser\Error(
                "Cannot use Foo as self because 'self' is a special class name",
                ['startLine' => 4]
            ),
            'use_as_self.php'
        );
    }
}
