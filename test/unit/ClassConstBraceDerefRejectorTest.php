<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ClassConstBraceDerefRejector;
use PHPCompiler\Compiler\CompileFatal;
use PHPCompiler\CompilerVersion;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

final class ClassConstBraceDerefRejectorTest extends TestCase
{
    public function testRejectsSingleQuotedBraceDerefOnReferenceProfile(): void
    {
        if (CompilerVersion::supportsClassConstBraceDeref()) {
            $this->markTestSkipped('PHP 8.3+ allows class const brace deref');
        }

        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage('unexpected token ";"');

        ClassConstBraceDerefRejector::reject(
            "<?php\nclass C { public const X = 42; }\necho C::{'X'};\n",
            'test.php'
        );
    }

    public function testRuntimeRejectsMaintainerGapRepro(): void
    {
        if (CompilerVersion::supportsClassConstBraceDeref()) {
            $this->markTestSkipped('PHP 8.3+ allows class const brace deref');
        }

        $runtime = new Runtime();
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage('unexpected token ";"');
        $runtime->parseAndCompile(
            file_get_contents(dirname(__DIR__).'/repro/maintainer_gap_class_const_brace_deref.php'),
            'maintainer_gap_class_const_brace_deref.php'
        );
    }

    public function testAllowsVariableBraceDerefOnReferenceProfile(): void
    {
        if (CompilerVersion::supportsClassConstBraceDeref()) {
            $this->markTestSkipped('PHP 8.3+ allows class const brace deref');
        }

        $code = "<?php echo C::{\$name};\n";
        $this->assertSame($code, ClassConstBraceDerefRejector::reject($code, 'test.php'));
    }

    public function testForwardProfileAllowsBraceDereference(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            $this->assertTrue(CompilerVersion::supportsClassConstBraceDeref());
            $code = <<<'PHP'
<?php
class C { public const X = 42; public const Y = 'ok'; }
echo C::{'X'}, "\n", C::{"Y"}, "\n";
PHP;
            $this->assertSame($code, ClassConstBraceDerefRejector::reject($code, 'brace_deref.php'));
            $runtime = new Runtime();
            $block = $runtime->parseAndCompile($code, 'brace_deref.php');
            $this->assertNotNull($block);
            ob_start();
            $runtime->run($block);
            $this->assertSame("42\nok\n", ob_get_clean());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
