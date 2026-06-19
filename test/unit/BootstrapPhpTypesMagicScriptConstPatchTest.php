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
            'return [Type::string()]',
            $content,
            'TypeReconstructor must resolve __DIR__/__FILE__ as string (#9833)'
        );
        $this->assertStringContainsString(
            'FirstClassCallable::KIND_METHOD',
            $content,
            'Run script/apply-patches.sh (php-types-first-class-callable.patch) before CI'
        );
        $this->assertStringContainsString(
            'new Type(Type::TYPE_ARRAY)',
            $content,
            'FCC overlay must use TYPE_ARRAY constructor, not Type::array() (#4957, #6932)'
        );
        $this->assertStringNotContainsString(
            'return [Type::array()];',
            $content,
            'TypeReconstructor must not call missing Type::array() on FCC path (#6932)'
        );
    }
}
