<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPUnit\Framework\TestCase;

/**
 * Issue #6342 / #19723: move_uploaded_file upload temp registry — no phpc_upload_temp.c.
 *
 * @group aot-lint
 */
final class UploadTempRuntimeShrinkTest extends TestCase
{
    public function testRuntimeShrinkRemovesUploadTempC(): void
    {
        $root = dirname(__DIR__, 3);
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/phpc_upload_temp.c');

        $linker = (string) file_get_contents($root.'/lib/AOT/Linker.php');
        $this->assertStringNotContainsString('phpc_upload_temp.c', $linker);
        $this->assertStringNotContainsString('phpc_upload_temp', $linker);

        $kernel = (string) file_get_contents($root.'/ext/standard/JitUploadTempKernel.php');
        $this->assertStringContainsString('UploadTempJitHelper', $kernel);
        $this->assertStringNotContainsString('emitPathHasTraversal', $kernel);
        $this->assertStringNotContainsString('emitIsValidTemp', $kernel);
        $this->assertStringContainsString('__compiler_is_uploaded_file', $kernel);
        $this->assertStringContainsString('__compiler_move_uploaded_file', $kernel);
        $this->assertLessThan(370, \substr_count($kernel, "\n") + 1);

        $orchestrator = (string) file_get_contents($root.'/lib/JIT/Builtin/UploadTempJit.php');
        $this->assertStringContainsString('JitUploadTempKernel::implement', $orchestrator);

        $helper = (string) file_get_contents($root.'/ext/standard/UploadTempJitHelper.php');
        $this->assertStringContainsString('VmFs::isValidUploadTempPath', $helper);
        $this->assertStringContainsString('VmFs::moveUploadedFile', $helper);

        $web = (string) file_get_contents($root.'/lib/Web/UploadTemp.php');
        $this->assertStringContainsString('VmFs::UPLOAD_TEMP_PREFIX', $web);
        $this->assertStringContainsString('isValidUploadTempPath', $web);
    }
}
