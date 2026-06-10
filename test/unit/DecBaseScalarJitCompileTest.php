<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only verify for dec* / *dec base-conversion scalar coercion (#4211, #4217).
 *
 * php-src: ext/standard/math.c
 *
 * @group llvm
 */
final class DecBaseScalarJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — dec base scalar JIT compile test needs LLVM');
        }
    }

    /**
     * @dataProvider compileOnlyFixtureProvider
     */
    public function testCompileOnlyFixture(string $relativePath): void
    {
        $path = $this->repoRoot.'/'.$relativePath;
        $this->assertFileExists($path);
        $code = (string) file_get_contents($path);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, basename($path));
        $this->assertNotNull($block);
        $runtime->jitCompileBlock($block);
        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function compileOnlyFixtureProvider(): array
    {
        return [
            'dechex_float_operand' => ['test/fixtures/aot/compile-only/dechex_float_operand.php'],
            'dechex_enum_operand' => ['test/fixtures/aot/compile-only/dechex_enum_operand.php'],
            'hexdec_scalar_coerce' => ['test/fixtures/aot/compile-only/hexdec_scalar_coerce.php'],
        ];
    }

    public function testDechexEnumCaseTypeErrorLowering(): void
    {
        $code = <<<'PHP'
<?php
enum E: int { case A = 10; }
dechex(E::A);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'dechex_enum_jit_compile.php');
        $runtime->jitCompileBlock($block);

        $context = $runtime->loadJitContext();
        $bc = $context->module->printToString();
        $this->assertStringContainsString(
            'dechex(): Argument #1 ($num) must be of type int',
            $bc
        );
    }
}
