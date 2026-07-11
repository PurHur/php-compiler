<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\EnumCaseListRewriter;
use PHPCompiler\EnumCaseListSyntaxRejector;
use PHPUnit\Framework\TestCase;

/** Enum case list syntax reference profile gate (#16665). */
final class EnumCaseListSyntaxReferenceProfileTest extends TestCase
{
    public function testSupportsEnumCaseListFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsEnumCaseList());
    }

    public function testSupportsEnumCaseListTrueWhenProfile85(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.5');
        try {
            $this->assertTrue(CompilerVersion::supportsEnumCaseList());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testRejectorThrowsOnCommaSeparatedEnumCases(): void
    {
        if (CompilerVersion::supportsEnumCaseList()) {
            $this->markTestSkipped('enum case list syntax enabled on PHP 8.5+ target');
        }
        $this->expectException(\PHPCompiler\Compiler\CompileFatal::class);
        $this->expectExceptionMessage(EnumCaseListRewriter::REFERENCE_PROFILE_UNEXPECTED_COMMA);
        EnumCaseListSyntaxRejector::reject(
            file_get_contents(dirname(__DIR__).'/repro-maintainer/parity_enum_case_list.php'),
            'parity_enum_case_list.php'
        );
    }

    public function testRewriterNoOpWhenEnumCaseListDisabled(): void
    {
        if (CompilerVersion::supportsEnumCaseList()) {
            $this->markTestSkipped('enum case list syntax enabled on PHP 8.5+ target');
        }
        $src = <<<'PHP'
<?php
enum E {
    case A, B, C;
}
PHP;
        $this->assertSame($src, EnumCaseListRewriter::rewrite($src));
    }

    public function testRuntimeRejectsMaintainerGapRepro(): void
    {
        if (CompilerVersion::supportsEnumCaseList()) {
            $this->markTestSkipped('enum case list syntax enabled on PHP 8.5+ target');
        }
        $runtime = new \PHPCompiler\Runtime();
        try {
            $runtime->parseAndCompile(
                file_get_contents(dirname(__DIR__).'/repro-maintainer/parity_enum_case_list.php'),
                'parity_enum_case_list.php'
            );
            $this->fail('Expected compile failure');
        } catch (\PHPCompiler\Compiler\CompileFatal $e) {
            $this->assertStringContainsString(EnumCaseListRewriter::REFERENCE_PROFILE_UNEXPECTED_COMMA, $e->getMessage());
        }
    }
}
