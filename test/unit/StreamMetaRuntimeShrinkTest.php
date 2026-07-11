<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StreamMetaJitHelper;
use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** StreamMetaJit routes through StreamMetaJitHelper PHP not inline feof/fcntl LLVM (#13846). */
final class StreamMetaRuntimeShrinkTest extends TestCase
{
    public function testStreamMetaJitUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamMetaJit.php');
        $this->assertStringContainsString('StreamMetaJitHelper', $source);
        $this->assertStringContainsString('JitNestedHelperCoerce', $source);
        $this->assertStringNotContainsString('emitGetMetaData', $source);
        $this->assertStringNotContainsString('emitSetBlocking', $source);
        $this->assertStringNotContainsString("lookupFunction('fcntl')", $source);
        $this->assertStringNotContainsString("lookupFunction('feof')", $source);
        $this->assertStringNotContainsString("lookupFunction('strncmp')", $source);
        $this->assertStringNotContainsString('phpc_stream_handles', $source);
        $this->assertLessThan(220, \substr_count($source, "\n") + 1);
    }

    public function testStreamMetaJitHelperDelegatesToVmFs(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/StreamMetaJitHelper.php');
        $this->assertStringContainsString('VmFs::streamGetMetaData', $source);
        $this->assertStringContainsString('VmFs::streamSetBlocking', $source);
    }

    public function testStreamMetaJitHelperMatchesVmFs(): void
    {
        $handle = VmFs::fopen('php://memory', 'r+');
        $this->assertNotFalse($handle);
        $expected = VmFs::streamGetMetaData($handle);
        $this->assertNotFalse($expected);
        $actual = StreamMetaJitHelper::getMetaDataArgv($handle);
        $this->assertNotNull($actual);
        foreach (['uri', 'stream_type', 'wrapper_type', 'seekable'] as $key) {
            $exp = $expected->find($key);
            $act = $actual->find($key);
            $this->assertNotNull($exp, 'expected key: '.$key);
            $this->assertNotNull($act, 'actual key: '.$key);
            if (Variable::TYPE_STRING === $exp->type) {
                $this->assertSame($exp->toString(), $act->toString(), $key);
            }
        }
        $this->assertSame(1, StreamMetaJitHelper::setBlockingArgv($handle, 1));
        VmFs::fclose($handle);
        $this->assertNull(StreamMetaJitHelper::getMetaDataArgv($handle));
    }
}
