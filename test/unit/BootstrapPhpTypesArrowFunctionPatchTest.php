<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Guard: php-types must tolerate php-cfg arrow-function ops on the self-host spine.
 */
final class BootstrapPhpTypesArrowFunctionPatchTest extends TestCase
{
    public function testArrowFunctionPatchApplied(): void
    {
        $reconstructorFile = dirname(__DIR__, 2).'/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php';
        $this->assertFileExists($reconstructorFile);
        $content = (string) file_get_contents($reconstructorFile);
        $this->assertStringContainsString(
            'function resolveOp_Expr_ArrowFunction',
            $content,
            'Run script/apply-patches.sh (php-types-arrow-function.patch) before CI'
        );
    }
}

