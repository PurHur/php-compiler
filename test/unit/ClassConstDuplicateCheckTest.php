<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #5219 */
final class ClassConstDuplicateCheckTest extends TestCase
{
    public function testDuplicateClassConstantFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot redefine class constant C::X');
        $runtime->parseAndCompile(<<<'PHP'
<?php
class C {
    public const X = 1;
    public const X = 2;
}
PHP,
            'duplicate_class_const.php'
        );
    }

    public function testDistinctClassConstantsStillCompile(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
class C {
    public const X = 1;
    public const Y = 2;
}
echo C::X;
PHP,
            'class_const_ok.php'
        );
        $this->assertNotNull($block);
    }
}
