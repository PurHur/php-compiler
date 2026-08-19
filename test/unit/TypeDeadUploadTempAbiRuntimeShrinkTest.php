<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on empty upload-temp compiler ABI shells from Builtin\Type (#32499).
 *
 * User-script is_uploaded_file()/move_uploaded_file() stay PHP helpers.
 * Kernel owner declares module-locally (getNamedFunction first) so leftover Type
 * addFunction cannot mint name.1 (#31894 / #32122).
 */
final class TypeDeadUploadTempAbiRuntimeShrinkTest extends TestCase
{
    /** @return list<string> */
    private function droppedAbis(): array
    {
        return [
            '__compiler_move_uploaded_file',
            '__compiler_is_uploaded_file',
        ];
    }

    public function testTypeBuiltinDropsLeftoverAlwaysOnUploadTempAbis(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#32499', $type);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertStringNotContainsString(
                "addFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-declare {$sym} (#32499)"
            );
            $this->assertStringNotContainsString(
                "registerFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-register {$sym} (#32499)"
            );
        }
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        $this->assertStringContainsString("registerFunction('__compiler_http_build_query'", $type);
    }

    public function testKernelOwnerDeclaresUploadTempAbisModuleLocally(): void
    {
        $kernel = (string) file_get_contents(__DIR__.'/../../ext/standard/JitUploadTempKernel.php');
        $this->assertStringContainsString("'__compiler_move_uploaded_file'", $kernel);
        $this->assertStringContainsString("'__compiler_is_uploaded_file'", $kernel);
        $this->assertStringContainsString("getNamedFunction('__compiler_is_uploaded_file')", $kernel);
        $this->assertStringContainsString("getNamedFunction(\$abiName)", $kernel);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $kernel);
        $this->assertStringContainsString('#32499', $kernel);
    }

    public function testPhpHelpersRemainForDroppedUserScriptBuiltins(): void
    {
        $this->assertStringContainsString(
            'StringFsDir::ensureLinked',
            (string) file_get_contents(__DIR__.'/../../ext/standard/JitIsUploadedFile.php')
        );
        $this->assertStringContainsString(
            "lookupFunction('__compiler_is_uploaded_file')",
            (string) file_get_contents(__DIR__.'/../../ext/standard/JitIsUploadedFile.php')
        );
        $this->assertStringContainsString(
            'StringFsDir::ensureLinked',
            (string) file_get_contents(__DIR__.'/../../ext/standard/JitMoveUploadedFile.php')
        );
        $this->assertStringContainsString(
            "lookupFunction('__compiler_move_uploaded_file')",
            (string) file_get_contents(__DIR__.'/../../ext/standard/JitMoveUploadedFile.php')
        );
        $this->assertStringContainsString(
            'UploadTempJitHelper::moveUploadedFile',
            (string) file_get_contents(__DIR__.'/../../ext/standard/JitUploadTempKernel.php')
        );
        $this->assertStringContainsString(
            'function isValidUploadTempPath(',
            (string) file_get_contents(__DIR__.'/../../ext/standard/VmFs.php')
        );
        $this->assertStringContainsString(
            'function moveUploadedFile(',
            (string) file_get_contents(__DIR__.'/../../ext/standard/VmFs.php')
        );
    }
}
