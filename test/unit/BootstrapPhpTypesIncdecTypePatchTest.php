<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Guard: php-types must preserve inc/dec operand types on the self-host spine (#3552).
 */
final class BootstrapPhpTypesIncdecTypePatchTest extends TestCase
{
    public function testIncdecTypePatchApplied(): void
    {
        $reconstructorFile = dirname(__DIR__, 2).'/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php';
        $this->assertFileExists($reconstructorFile);
        $content = (string) file_get_contents($reconstructorFile);
        $this->assertStringContainsString(
            "case 'Expr_PostInc':",
            $content,
            'Run script/apply-patches.sh (php-types-incdec-type.patch) before CI'
        );
        $this->assertStringContainsString(
            '$resolved->contains($op->read)',
            $content,
            'inc/dec cases must propagate resolved read operand type'
        );
    }
}
