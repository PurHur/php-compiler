<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\set_include_path;
use PHPCompiler\ext\standard\stream_resolve_include_path;
use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** stream_resolve_include_path() VM builtin (#6051). */
final class StreamResolveIncludePathTest extends TestCase
{
    public function testFunctionExistsOnRuntime(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        $this->assertTrue(VmReflection::functionExists($ctx, 'stream_resolve_include_path'));
        $this->assertTrue(VmReflection::functionExists($ctx, 'get_include_path'));
        $this->assertTrue(VmReflection::functionExists($ctx, 'set_include_path'));
    }

    public function testEnumCaseOperandTypeError(): void
    {
        $runtime = new Runtime();
        $fn = new stream_resolve_include_path();
        $enum = new ClassEntry('E');
        $enum->isEnum = true;
        $enum->backedType = 'string';
        $backing = new VMVariable();
        $backing->string('x');
        $case = EnumCaseSupport::createCase($enum, 'A', $backing);
        $frame = $fn->getFrame($runtime->vmContext);
        $frame->calledArgs[] = $case;
        $frame->returnVar = new VMVariable();
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('stream_resolve_include_path(): Argument #1 ($filename) must be of type string, E given');
        $fn->execute($frame);
    }

    public function testResolvesFromIncludePath(): void
    {
        $dir = \sys_get_temp_dir().'/phpc_stream_resolve_'.\getmypid();
        @\mkdir($dir);
        $file = $dir.'/target.inc';
        \file_put_contents($file, 'x');
        $runtime = new Runtime();
        $set = new set_include_path();
        $resolve = new stream_resolve_include_path();
        $setFrame = $set->getFrame($runtime->vmContext);
        $pathArg = new VMVariable();
        $pathArg->string($dir);
        $setFrame->calledArgs[] = $pathArg;
        $setFrame->returnVar = new VMVariable();
        $set->execute($setFrame);
        $frame = $resolve->getFrame($runtime->vmContext);
        $nameArg = new VMVariable();
        $nameArg->string('target.inc');
        $frame->calledArgs[] = $nameArg;
        $frame->returnVar = new VMVariable();
        $resolve->execute($frame);
        $result = $frame->returnVar->resolveIndirect();
        $this->assertSame(VMVariable::TYPE_STRING, $result->type);
        $this->assertTrue(\is_file($result->toString()));
        @\unlink($file);
        @\rmdir($dir);
    }

    public function testDotAndEmptyResolveToCwd(): void
    {
        $cwd = \getcwd();
        if (false === $cwd) {
            $this->markTestSkipped('getcwd unavailable');
        }
        $runtime = new Runtime();
        $resolve = new stream_resolve_include_path();
        foreach (['.', ''] as $filename) {
            $frame = $resolve->getFrame($runtime->vmContext);
            $nameArg = new VMVariable();
            $nameArg->string($filename);
            $frame->calledArgs[] = $nameArg;
            $frame->returnVar = new VMVariable();
            $resolve->execute($frame);
            $result = $frame->returnVar->resolveIndirect();
            $this->assertSame(VMVariable::TYPE_STRING, $result->type);
            $this->assertSame($cwd, $result->toString());
        }
    }
}
