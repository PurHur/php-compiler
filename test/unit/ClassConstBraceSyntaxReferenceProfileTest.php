<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Class constant brace dereference withheld on 8.2 reference profile (#16597). */
final class ClassConstBraceSyntaxReferenceProfileTest extends TestCase
{
    public function testSupportsClassConstBraceDereferenceFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsClassConstBraceDereference());
    }

    public function testRejectorRejectsMaintainerGapRepro(): void
    {
        $code = file_get_contents(dirname(__DIR__).'/repro/maintainer_gap_class_const_brace_deref.php');
        $this->expectException(\PHPCompiler\Compiler\CompileFatal::class);
        $this->expectExceptionMessage('syntax error, unexpected token ";", expecting "("');
        ClassConstBraceSyntaxRejector::reject($code, 'maintainer_gap_class_const_brace_deref.php');
    }

    public function testRuntimeRejectsMaintainerGapRepro(): void
    {
        $runtime = new Runtime();
        $this->expectException(\PHPCompiler\Compiler\CompileFatal::class);
        $this->expectExceptionMessage('syntax error, unexpected token ";", expecting "("');
        $runtime->parseAndCompile(
            file_get_contents(dirname(__DIR__).'/repro/maintainer_gap_class_const_brace_deref.php'),
            'maintainer_gap_class_const_brace_deref.php'
        );
    }

    public function testForwardProfileAllowsBraceDereference(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            $this->assertTrue(CompilerVersion::supportsClassConstBraceDereference());
            $code = <<<'PHP'
<?php
class C { public const X = 42; public const Y = 'ok'; }
echo C::{'X'}, "\n", C::{"Y"}, "\n";
PHP;
            $this->assertSame($code, ClassConstBraceSyntaxRejector::reject($code, 'brace_deref.php'));
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

    public function testStaticIdentifierFormStillCompilesOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            '<?php class C { public const X = 42; } echo C::X, "\n";',
            'class_const_static.php'
        );
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("42\n", ob_get_clean());
    }

    public function testDynamicVariableBraceFormNotRejectedOnReferenceProfile(): void
    {
        $code = <<<'PHP'
<?php
class C { public const X = 1; }
$name = 'X';
var_dump(C::{$name});
PHP;
        $this->assertSame($code, ClassConstBraceSyntaxRejector::reject($code, 'dynamic.php'));
    }
}
