<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;
use PHPCompiler\Lint\Linter;

/** Bootstrap-inventory lint must reset NameResolver between files (#9252). */
final class LintBootstrapInventoryNameResolverTest extends TestCase
{
    public function testBootstrapInventoryLintDoesNotReportStaleUseImportCollisions(): void
    {
        $root = dirname(__DIR__, 2);
        $linter = new Linter();
        $issues = $linter->lintFileStandalone($root.'/ext/bcmath/BcmathFunction.php');
        $this->assertSame([], $issues, 'BcmathFunction.php must lint clean after NameResolver reset');
    }
}
