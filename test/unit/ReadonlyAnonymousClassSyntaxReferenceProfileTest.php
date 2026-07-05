<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Ast\ReadonlyAnonymousClassSyntax;
use PHPUnit\Framework\TestCase;

/** `new readonly class` reference profile gate (#16255). */
final class ReadonlyAnonymousClassSyntaxReferenceProfileTest extends TestCase
{
    public function testSupportsReadonlyAnonymousClassFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsReadonlyAnonymousClass());
    }

    public function testSupportsReadonlyAnonymousClassTrueWhenProfile83(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            $this->assertTrue(CompilerVersion::supportsReadonlyAnonymousClass());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testRejectorThrowsOnMaintainerGapRepro(): void
    {
        if (CompilerVersion::supportsReadonlyAnonymousClass()) {
            $this->markTestSkipped('new readonly class enabled on PHP 8.3+ forward profile');
        }
        $this->expectException(\PHPCompiler\Compiler\CompileFatal::class);
        $this->expectExceptionMessage(ReadonlyAnonymousClassSyntax::REFERENCE_PROFILE_UNEXPECTED_READONLY);
        ReadonlyAnonymousClassSyntaxRejector::reject(
            file_get_contents(dirname(__DIR__).'/repro/maintainer_gap_anonymous_readonly_class.php'),
            'maintainer_gap_anonymous_readonly_class.php'
        );
    }

    public function testRuntimeRejectsMaintainerGapRepro(): void
    {
        if (CompilerVersion::supportsReadonlyAnonymousClass()) {
            $this->markTestSkipped('new readonly class enabled on PHP 8.3+ forward profile');
        }
        $runtime = new Runtime();
        try {
            $runtime->parseAndCompile(
                file_get_contents(dirname(__DIR__).'/repro/maintainer_gap_anonymous_readonly_class.php'),
                'maintainer_gap_anonymous_readonly_class.php'
            );
            $this->fail('Expected compile failure');
        } catch (\PHPCompiler\Compiler\CompileFatal $e) {
            $this->assertStringContainsString(
                ReadonlyAnonymousClassSyntax::REFERENCE_PROFILE_UNEXPECTED_READONLY,
                $e->getMessage()
            );
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
}
