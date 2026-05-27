<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Guard: php-types must lower Expr_MagicScriptConst on the self-host spine (#2826).
 */
final class BootstrapPhpTypesMagicScriptConstPatchTest extends TestCase
{
    public function testMagicScriptConstPatchApplied(): void
    {
        $reconstructorFile = dirname(__DIR__, 2).'/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php';
        $this->assertFileExists($reconstructorFile);
        $content = (string) file_get_contents($reconstructorFile);
        $this->assertStringContainsString(
            'MagicScriptConst::KIND_LINE',
            $content,
            'Run script/apply-patches.sh (php-types-magic-script-const.patch) before CI'
        );
        $this->assertStringContainsString(
            'FirstClassCallable::KIND_METHOD',
            $content,
            'Run script/apply-patches.sh (php-types-first-class-callable.patch) before CI'
        );
    }
}
