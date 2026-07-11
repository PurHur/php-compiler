<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Ast\CloneWithDesugar;
use PHPUnit\Framework\TestCase;

/** Clone-with syntax reference profile gate (#12987). */
final class CloneWithSyntaxReferenceProfileTest extends TestCase
{
    public function testSupportsCloneWithSyntaxFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsCloneWithSyntax());
    }

    public function testRejectorThrowsOnNamedWithArgForm(): void
    {
        if (CompilerVersion::supportsCloneWithSyntax()) {
            $this->markTestSkipped('clone-with syntax enabled on PHP 8.4.0+ target');
        }
        $this->expectException(\PHPCompiler\Compiler\CompileFatal::class);
        $this->expectExceptionMessage(CloneWithDesugar::REFERENCE_PROFILE_UNEXPECTED_COMMA);
        CloneWithSyntaxRejector::reject(
            file_get_contents(dirname(__DIR__).'/repro/maintainer_gap_clone_with_syntax.php'),
            'clone_with_syntax.php'
        );
    }

    public function testRejectorThrowsOnWithBlockForm(): void
    {
        if (CompilerVersion::supportsCloneWithSyntax()) {
            $this->markTestSkipped('clone-with syntax enabled on PHP 8.4.0+ target');
        }
        $this->expectException(\PHPCompiler\Compiler\CompileFatal::class);
        $this->expectExceptionMessage(CloneWithDesugar::REFERENCE_PROFILE_UNEXPECTED_WITH);
        CloneWithSyntaxRejector::reject(
            '<?php $d = clone $c with { x: 2 };',
            'clone_with_block.php'
        );
    }

    public function testDesugarNoOpWhenCloneWithDisabled(): void
    {
        if (CompilerVersion::supportsCloneWithSyntax()) {
            $this->markTestSkipped('clone-with syntax enabled on PHP 8.4.0+ target');
        }
        $src = '<?php $q = clone ($p, with: [\'x\' => 9]);';
        $this->assertSame($src, CloneWithDesugar::desugar($src));
    }

    public function testRuntimeRejectsMaintainerGapRepro(): void
    {
        if (CompilerVersion::supportsCloneWithSyntax()) {
            $this->markTestSkipped('clone-with syntax enabled on PHP 8.4.0+ target');
        }
        $runtime = new Runtime();
        try {
            $runtime->parseAndCompile(
                file_get_contents(dirname(__DIR__).'/repro/maintainer_gap_clone_with_syntax.php'),
                'maintainer_gap_clone_with_syntax.php'
            );
            $this->fail('Expected compile failure');
        } catch (\PHPCompiler\Compiler\CompileFatal $e) {
            $this->assertStringContainsString(CloneWithDesugar::REFERENCE_PROFILE_UNEXPECTED_COMMA, $e->getMessage());
        }
    }
}
