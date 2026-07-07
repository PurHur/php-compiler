<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Ast\ReadonlyAnonymousClassSyntax;
use PHPUnit\Framework\TestCase;

/** `new readonly class` withheld on 8.2 reference profile; forward via PHP_COMPILER_PROFILE (#16255, #16379). */
final class ReadonlyAnonymousClassSyntaxReferenceProfileTest extends TestCase
{
    public function testSupportsReadonlyAnonymousClassFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsReadonlyAnonymousClass());
    }

    public function testRejectorRejectsMaintainerGap82Repro(): void
    {
        $code = file_get_contents(dirname(__DIR__).'/repro/maintainer_gap_readonly_anon_class_82.php');
        $this->expectException(\PHPCompiler\Compiler\CompileFatal::class);
        $this->expectExceptionMessage('syntax error, unexpected token "readonly"');
        ReadonlyAnonymousClassSyntaxRejector::reject($code, 'maintainer_gap_readonly_anon_class_82.php');
    }

    public function testRuntimeRejectsMaintainerGap82Repro(): void
    {
        $runtime = new Runtime();
        $this->expectException(\PHPCompiler\Compiler\CompileFatal::class);
        $this->expectExceptionMessage('syntax error, unexpected token "readonly"');
        $runtime->parseAndCompile(
            file_get_contents(dirname(__DIR__).'/repro/maintainer_gap_readonly_anon_class_82.php'),
            'maintainer_gap_readonly_anon_class_82.php'
        );
    }

    public function testForwardProfileAllowsMaintainerGapRepro(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            $this->assertTrue(CompilerVersion::supportsReadonlyAnonymousClass());
            $code = file_get_contents(dirname(__DIR__).'/repro/maintainer_gap_anonymous_readonly_class.php');
            $this->assertSame($code, ReadonlyAnonymousClassSyntaxRejector::reject($code, 'maintainer_gap_anonymous_readonly_class.php'));
            $runtime = new Runtime();
            $block = $runtime->parseAndCompile($code, 'maintainer_gap_anonymous_readonly_class.php');
            $this->assertNotNull($block);
            ob_start();
            $runtime->run($block);
            $this->assertSame("1\n", ob_get_clean());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testNamedReadonlyClassStillCompilesOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            '<?php readonly class R { public function __construct(public int $x = 1) {} } $o = new R(); var_export($o->x);',
            'named_readonly_class.php'
        );
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame('1', ob_get_clean());
    }

    public function testBoundedScanDoesNotTokenizeHugeBundleWithUnrelatedNew(): void
    {
        $huge = '<?php '.str_repeat('$x = new stdClass(); ', 200_000);
        $this->assertNull(ReadonlyAnonymousClassSyntax::referenceProfileSyntaxError($huge));
    }

    public function testDetectsNewReadonlyClassWithCommentsBetweenTokens(): void
    {
        $code = '<?php $o = new /*x*/ readonly //y
class { public int $x = 1; };';
        $error = ReadonlyAnonymousClassSyntax::referenceProfileSyntaxError($code);
        $this->assertNotNull($error);
        $this->assertSame(ReadonlyAnonymousClassSyntax::REFERENCE_PROFILE_UNEXPECTED_READONLY, $error['message']);
    }
}
