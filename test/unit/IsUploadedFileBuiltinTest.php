<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\is_uploaded_file;
use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\EnumCaseSupport;
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

    public function testEnumCaseFilenameTypeError(): void
    {
        $runtime = new Runtime();
        $fn = new is_uploaded_file();
        $enum = new ClassEntry('E');
        $enum->isEnum = true;
        $enum->backedType = 'string';
        $backing = new VMVariable();
        $backing->string('/tmp/x');
        $case = EnumCaseSupport::createCase($enum, 'A', $backing);

        $frame = $fn->getFrame($runtime->vmContext);
        $frame->calledArgs = [$case];
        $frame->returnVar = new VMVariable();
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('is_uploaded_file(): Argument #1 ($filename) must be of type string, E given');
        $fn->execute($frame);
    }

    public function testNullFilenameStrictTypeError(): void
    {
        $runtime = new Runtime();
        $fn = new is_uploaded_file();
        $strictBlock = new Block(null);
        $strictBlock->strictTypes = true;
        $parent = new Frame(null, $strictBlock, null);
        $frame = $fn->getFrame($runtime->vmContext);
        $frame->parent = $parent;
        $null = new VMVariable();
        $null->null();
        $frame->calledArgs = [$null];
        $frame->returnVar = new VMVariable();
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('is_uploaded_file(): Argument #1 ($filename) must be of type string, null given');
        $fn->execute($frame);
    }

    public function testNullFilenameNonStrictReturnsFalse(): void
    {
        $runtime = new Runtime();
        $fn = new is_uploaded_file();
        $frame = $fn->getFrame($runtime->vmContext);
        $null = new VMVariable();
        $null->null();
        $frame->calledArgs = [$null];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $this->assertFalse($frame->returnVar->resolveIndirect()->toBool());
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
