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
        $this->assertStringContainsString(
            'return [Type::never()]',
            $content,
            'Expr_Throw must type-reconstruct as never (#6746)'
        );
        $typeFile = dirname(__DIR__, 2).'/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php';
        $this->assertFileExists($typeFile);
        $typeContent = (string) file_get_contents($typeFile);
        $this->assertStringContainsString(
            'function never(): self',
            $typeContent,
            'Run script/apply-patches.sh (php-types-never-type overlay) before CI (#4137)'
        );
        $this->assertStringContainsString(
            'instanceof CfgType\\Never_',
            $typeContent,
            'Type::fromTypeDecl must lower CfgType\\Never_ (#7329)'
        );
        $this->assertStringContainsString(
            "case 'never':",
            $typeContent,
            'Type::fromDecl must handle never (#7329)'
        );
    }
}
