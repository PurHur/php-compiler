<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\OpCode;
use PHPCompiler\VM\VmInclude;
use PHPUnit\Framework\TestCase;

/** VmInclude SSOT for self-host spine include guards (#10063) + missing-file diagnostics (#30029). */
final class VmIncludeTest extends TestCase
{
    public function testPathMatchesSelfHostSpineSkipSuffix(): void
    {
        self::assertTrue(VmInclude::pathMatchesSelfHostSpineSkipSuffix('vendor/autoload.php'));
        self::assertTrue(VmInclude::pathMatchesSelfHostSpineSkipSuffix('/repo/vendor/autoload.php'));
        self::assertFalse(VmInclude::pathMatchesSelfHostSpineSkipSuffix('lib/VM.php'));
    }

    public function testMissingIncludeDiagnosticsMatchZend(): void
    {
        self::assertSame('include', VmInclude::kindKeyword(OpCode::INCLUDE_KIND_INCLUDE));
        self::assertSame('require_once', VmInclude::kindKeyword(OpCode::INCLUDE_KIND_REQUIRE_ONCE));
        self::assertSame(
            'include(/tmp/x.php): Failed to open stream: No such file or directory',
            VmInclude::failedToOpenStreamMessage('include', '/tmp/x.php')
        );
        self::assertSame(
            "include(): Failed opening '/tmp/x.php' for inclusion (include_path='.')",
            VmInclude::failedOpeningForInclusionMessage('include', '/tmp/x.php', '.')
        );
        self::assertSame(
            "Failed opening required '/tmp/x.php' (include_path='.')",
            VmInclude::failedOpeningRequiredMessage('/tmp/x.php', '.')
        );
    }

    public function testIncludeSyntaxParseHelpersMatchZendChannel(): void
    {
        $parser = new \PhpParser\Error('Syntax error, unexpected T_LNUMBER, expecting \';\'', ['startLine' => 1]);
        self::assertTrue(VmInclude::isCatchableSyntaxParseThrowable($parser));
        self::assertSame(
            'syntax error, unexpected T_LNUMBER, expecting \';\'',
            VmInclude::syntaxParseMessage($parser)
        );
        self::assertSame(1, VmInclude::syntaxParseLine($parser));
        self::assertTrue(VmInclude::isCatchableSyntaxParseThrowable(new \ParseError('syntax error, unexpected integer "2"')));
        self::assertFalse(VmInclude::isCatchableSyntaxParseThrowable(new \RuntimeException('failed to open stream')));
    }

    public function testShouldSkipSelfHostSpineCliIncludeWhenSelfHostAot(): void
    {
        $prev = getenv('PHP_COMPILER_SELFHOST_AOT');
        putenv('PHP_COMPILER_SELFHOST_AOT=1');
        try {
            self::assertTrue(VmInclude::shouldSkipSelfHostSpineCliInclude('vendor/autoload.php'));
            self::assertFalse(VmInclude::shouldSkipSelfHostSpineCliInclude('lib/Compiler.php'));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_SELFHOST_AOT');
            } else {
                putenv('PHP_COMPILER_SELFHOST_AOT='.$prev);
            }
        }
    }

    public function testShouldStubM3SidecarHostNonLiteralIncludeForLibSpineBundle(): void
    {
        $prev = getenv('PHP_COMPILER_LIB_SPINE_BUNDLE');
        putenv('PHP_COMPILER_LIB_SPINE_BUNDLE=1');
        try {
            self::assertTrue(
                VmInclude::shouldStubM3SidecarHostNonLiteralInclude('/compiler/lib/Compiler.php')
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_LIB_SPINE_BUNDLE');
            } else {
                putenv('PHP_COMPILER_LIB_SPINE_BUNDLE='.$prev);
            }
        }
    }
}
