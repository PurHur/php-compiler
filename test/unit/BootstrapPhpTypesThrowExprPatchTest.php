<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Guard: php-types must type-reconstruct Expr_Throw for assignment RHS (#4120).
 */
final class BootstrapPhpTypesThrowExprPatchTest extends TestCase
{
    public function testThrowExprPatchApplied(): void
    {
        $reconstructorFile = dirname(__DIR__, 2).'/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php';
        $this->assertFileExists($reconstructorFile);
        $content = (string) file_get_contents($reconstructorFile);
        $this->assertStringContainsString(
            "case 'Expr_Throw':",
            $content,
            'Run script/apply-patches.sh (php-types-throw-expr.patch) before CI'
        );
    }
}
