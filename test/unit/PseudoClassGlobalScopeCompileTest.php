<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #5024 — self/parent/static::class outside class scope */
final class PseudoClassGlobalScopeCompileTest extends TestCase
{
    /**
     * @dataProvider pseudoClassProvider
     */
    public function testPseudoClassConstFetchFailsAtCompileTime(string $keyword): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot use "'.$keyword.'" in the global scope');
        $runtime->parseAndCompile(
            '<?php class C extends stdClass {} echo '.$keyword.'::class;',
            'pseudo_class_global.php'
        );
    }

    /** @return iterable<string, array{string}> */
    public static function pseudoClassProvider(): iterable
    {
        yield 'parent' => ['parent'];
        yield 'self' => ['self'];
        yield 'static' => ['static'];
    }
}
