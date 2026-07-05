<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Ast\ReadonlyAnonymousClassSupport;
use PHPUnit\Framework\TestCase;

/** `new readonly class` reference profile gate (#16255). */
final class ReadonlyAnonymousClassReferenceProfileTest extends TestCase
{
    public function testSupportsReadonlyAnonymousClassFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsReadonlyAnonymousClass());
    }

    public function testRejectorThrowsOnNewReadonlyClass(): void
    {
        if (CompilerVersion::supportsReadonlyAnonymousClass()) {
            $this->markTestSkipped('new readonly class enabled on PHP 8.3+ forward profile');
        }
        $this->expectException(\PHPCompiler\Compiler\CompileFatal::class);
        $this->expectExceptionMessage(ReadonlyAnonymousClassSupport::REFERENCE_PROFILE_UNEXPECTED_READONLY);
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
                ReadonlyAnonymousClassSupport::REFERENCE_PROFILE_UNEXPECTED_READONLY,
                $e->getMessage()
            );
        }
    }

    public function testNamedReadonlyClassStillCompilesOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            '<?php readonly class R { public int $x; } echo "ok\n";',
            'readonly_named_class.php'
        );
        $this->assertNotNull($block);
    }
}
