<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPTypes\Type;
use PHPUnit\Framework\TestCase;

/**
 * Guard: php-types strips trailing prose from docblock type tokens (#2743).
 */
final class BootstrapPhpTypesDocblockTrailingPatchTest extends TestCase
{
    public function testStripTrailingDocTextPatchApplied(): void
    {
        $typeFile = dirname(__DIR__, 2).'/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php';
        $this->assertFileExists($typeFile);
        $content = (string) file_get_contents($typeFile);
        $this->assertStringContainsString(
            'stripTrailingDocText',
            $content,
            'Run script/apply-patches.sh (php-types-docblock-trailing-text.patch) before CI'
        );
    }

    public function testFromDeclStripsTrailingProse(): void
    {
        $type = Type::fromDecl('list<bool> parallel to values');
        $this->assertSame(Type::TYPE_ARRAY, $type->type);
    }

    public function testFromDeclAcceptsTypeWithDescription(): void
    {
        $type = Type::fromDecl('PHPTypes\\Type The type');
        $this->assertSame(Type::TYPE_OBJECT, $type->type);
        $this->assertSame('PHPTypes\\Type', $type->userType);
    }
}
