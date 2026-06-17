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

    /** Issue #8559: stripTrailingDocText must not truncate callable return types (OutputBufferHandlers @var). */
    public function testFromDeclPreservesCallableReturnType(): void
    {
        $type = Type::fromDecl('null|callable(string, string, ?Context): string');
        $this->assertSame(Type::TYPE_UNION, $type->type);
        $this->assertCount(2, $type->subTypes);
        $this->assertSame(Type::TYPE_NULL, $type->subTypes[0]->type);
        $this->assertSame(Type::TYPE_CALLABLE, $type->subTypes[1]->type);
    }

    /** Issue #9261: overlay must emit real newlines, not literal \\n inside a // comment. */
    public function testFromDeclTrailingCommaOverlayNotCorrupt(): void
    {
        $typeFile = dirname(__DIR__, 2).'/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php';
        $content = (string) file_get_contents($typeFile);
        $this->assertDoesNotMatchRegularExpression(
            '/Docblock union splits.*\\\\n\s+\$trimmedDecl/',
            $content,
            'Run script/apply-patches.sh — php-types-fromdecl-trailing-comma overlay must not write literal \\n'
        );
        $type = Type::fromDecl('string,');
        $this->assertSame(Type::TYPE_STRING, $type->type);
    }
}
