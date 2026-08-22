<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * ext/standard NestedJIT kernels must route ensureExternal through LibcExtern::ensureExternalDecl
 * (#33832 / peer #33774 / #31894 / #32122 name.1 mint class).
 *
 * @group aot-lint
 */
final class ExtKernelEnsureGetNamedRuntimeShrinkTest extends TestCase
{
    /** @return list<string> basename under ext/standard/ */
    private function kernelOwners(): array
    {
        return [
            'JitStreamIoKernel.php',
            'JitSessionStorageKernel.php',
            'JitStreamResourceKernel.php',
            'JitStreamCapsKernel.php',
            'JitStreamSyncKernel.php',
            'JitEnvLocalKernel.php',
            'JitFsGlobKernel.php',
            'JitUploadTempKernel.php',
            'JitParseStrUserScriptCstrKernel.php',
            'JitMicrotimeKernel.php',
            'JitProgressNoteKernel.php',
        ];
    }

    private function ensureExternalBody(string $src): string
    {
        if (!preg_match(
            '/private static function ensureExternal\s*\(/',
            $src,
            $m,
            PREG_OFFSET_CAPTURE
        )) {
            $this->fail('ensureExternal not found');
        }
        $start = (int) $m[0][1];
        $brace = strpos($src, '{', $start);
        $this->assertNotFalse($brace);
        $depth = 0;
        $len = strlen($src);
        for ($i = $brace; $i < $len; ++$i) {
            $ch = $src[$i];
            if ('{' === $ch) {
                ++$depth;
            } elseif ('}' === $ch) {
                --$depth;
                if (0 === $depth) {
                    return substr($src, $brace + 1, $i - $brace - 1);
                }
            }
        }
        $this->fail('unbalanced braces in ensureExternal');
    }

    public function testExtStandardKernelsEnsureExternalRoutesThroughLibcExtern(): void
    {
        $root = dirname(__DIR__, 2).'/ext/standard';
        foreach ($this->kernelOwners() as $base) {
            $src = (string) file_get_contents($root.'/'.$base);
            $this->assertStringContainsString('#33832', $src, "{$base} must cite #33832");
            $body = $this->ensureExternalBody($src);
            $this->assertStringContainsString(
                'LibcExtern::ensureExternalDecl($context, $name,',
                $body,
                "{$base}::ensureExternal must call LibcExtern::ensureExternalDecl (#33832)"
            );
            $this->assertStringNotContainsString(
                "module->addFunction(\$name, \$ft);\n            \$context->registerFunction(\$name, \$fn);",
                $body,
                "{$base} must not bare-addFunction after lookup miss (#33832)"
            );
            $this->assertDoesNotMatchRegularExpression(
                '/catch\s*\([^)]*\)\s*\{\s*\$\w+\s*=\s*\$context->module->addFunction/s',
                $body,
                "{$base}::ensureExternal must not catch→addFunction (#33832)"
            );
        }
    }
}
