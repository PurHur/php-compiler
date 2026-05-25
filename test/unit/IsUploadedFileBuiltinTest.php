<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\is_uploaded_file;
use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtin for is_uploaded_file() (#2204). */
final class IsUploadedFileBuiltinTest extends TestCase
{
    public function testValidUploadTemp(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), VmFs::UPLOAD_TEMP_PREFIX);
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, 'x');

        $runtime = new Runtime();
        $fn = new is_uploaded_file();
        $frame = $fn->getFrame($runtime->vmContext);
        $path = new VMVariable();
        $path->string($tmp);
        $frame->calledArgs = [$path];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $this->assertTrue($frame->returnVar->resolveIndirect()->toBool());
        @unlink($tmp);
    }

    public function testRejectsPlainTemp(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_plain_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, 'x');

        $runtime = new Runtime();
        $fn = new is_uploaded_file();
        $frame = $fn->getFrame($runtime->vmContext);
        $path = new VMVariable();
        $path->string($tmp);
        $frame->calledArgs = [$path];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $this->assertFalse($frame->returnVar->resolveIndirect()->toBool());
        @unlink($tmp);
    }
}
