<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Per-unit language profile from phpc.json / source pragma (#17681). */
final class LanguageProfileScopeTest extends TestCase
{
    private ?string $savedProfile = null;

    protected function setUp(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        $this->savedProfile = false === $prev ? null : $prev;
        putenv('PHP_COMPILER_PROFILE');
    }

    protected function tearDown(): void
    {
        if (null === $this->savedProfile) {
            putenv('PHP_COMPILER_PROFILE');
        } else {
            putenv('PHP_COMPILER_PROFILE='.$this->savedProfile);
        }
    }

    public function testSourcePragmaEnablesExitFunctionFormDuringScope(): void
    {
        $code = <<<'PHP'
<?php
// php-compiler-language-profile=8.4
exit(status: 0);
PHP;
        $this->assertFalse(CompilerVersion::supportsExitFunctionForm());
        $scope = LanguageProfileScope::beginForCompilationUnit($code, 'exit_pragma.php');
        try {
            $this->assertTrue($scope->wasApplied());
            $this->assertTrue(CompilerVersion::supportsExitFunctionForm());
            $this->assertSame($code, ExitFunctionSyntaxRejector::reject($code, 'exit_pragma.php'));
        } finally {
            $scope->end();
        }
        $this->assertFalse(CompilerVersion::supportsExitFunctionForm());
    }

    public function testExistingEnvProfileIsNotOverridden(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.2');
        $code = <<<'PHP'
<?php
// php-compiler-language-profile=8.4
exit(status: 0);
PHP;
        $scope = LanguageProfileScope::beginForCompilationUnit($code, 'exit_pragma.php');
        try {
            $this->assertFalse($scope->wasApplied());
            $this->assertFalse(CompilerVersion::supportsExitFunctionForm());
        } finally {
            $scope->end();
        }
    }

    public function testMaintainerGapExitReproParsesWithoutCliEnv(): void
    {
        $path = dirname(__DIR__).'/repro/maintainer_gap_exit_named_status.php';
        $code = file_get_contents($path);
        $this->assertIsString($code);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, $path);
        $this->assertNotNull($block);
    }
}
