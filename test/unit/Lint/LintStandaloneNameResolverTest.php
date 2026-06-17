<?php

declare(strict_types=1);

namespace PHPCompiler\Lint;

use PHPUnit\Framework\TestCase;

/** Issue #9252 — bootstrap-inventory lint must reset NameResolver between files. */
final class LintStandaloneNameResolverTest extends TestCase
{
    public function testStandaloneLintResetsUseImportsBetweenFiles(): void
    {
        $dir = sys_get_temp_dir().'/phpc_lint_use_reset_'.getmypid();
        $this->assertTrue(is_dir($dir) || mkdir($dir, 0775, true));
        $a = $dir.'/a.php';
        $b = $dir.'/b.php';
        file_put_contents($a, <<<'PHP'
<?php
namespace Foo;
use PHPCompiler\Frame;
class A {}
PHP
        );
        file_put_contents($b, <<<'PHP'
<?php
namespace Foo;
use PHPCompiler\Frame;
class B {}
PHP
        );
        try {
            $linter = new Linter();
            $this->assertSame([], $linter->lintFileStandalone($a));
            $this->assertSame([], $linter->lintFileStandalone($b));
        } finally {
            @unlink($a);
            @unlink($b);
            @rmdir($dir);
        }
    }

    public function testBcmathInventoryPathsLintCleanWithSharedLinter(): void
    {
        $repoRoot = dirname(__DIR__, 3);
        $linter = new Linter();
        foreach (glob($repoRoot.'/ext/bcmath/*.php') ?: [] as $path) {
            $issues = $linter->lintFileStandalone($path);
            $this->assertSame(
                [],
                $issues,
                substr($path, strlen($repoRoot) + 1).': '.($issues[0]->kind ?? '')
            );
        }
    }
}
