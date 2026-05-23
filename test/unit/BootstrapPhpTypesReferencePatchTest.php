<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Guard: php-types must lower PHPCfg Reference param types (bootstrap const_string_folder, #1056).
 */
final class BootstrapPhpTypesReferencePatchTest extends TestCase
{
    public function testCfgReferencePatchApplied(): void
    {
        $typeFile = dirname(__DIR__, 2).'/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php';
        $this->assertFileExists($typeFile);
        $content = (string) file_get_contents($typeFile);
        $this->assertStringContainsString(
            'instanceof CfgType\\Reference',
            $content,
            'Run script/apply-patches.sh (php-types-cfg-reference.patch) before CI'
        );
    }
}
