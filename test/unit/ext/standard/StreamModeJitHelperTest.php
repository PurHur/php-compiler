<?php

declare(strict_types=1);

namespace test\unit\ext\standard;

use PHPCompiler\ext\standard\StreamModeJitHelper;
use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmStreamMeta;
use PHPUnit\Framework\TestCase;

/** StreamModeRuntime routes through StreamModeJitHelper PHP (#13021). */
final class StreamModeJitHelperTest extends TestCase
{
    public function testStreamModeJitHelperDelegatesToVmFs(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../../../ext/standard/StreamModeJitHelper.php');
        $this->assertStringContainsString('VmFs::registerStreamMode', $source);
        $this->assertStringContainsString('VmStreamMeta::userFacingMode', $source);
    }

    public function testStreamModeJitHelperPlainfileRoundTrip(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'phpc_mode');
        $this->assertNotFalse($path);
        $handle = VmFs::fopen($path, 'r+');
        $this->assertNotFalse($handle);
        $this->assertSame('r+', StreamModeJitHelper::modeForHandle($handle));
        $this->assertSame('r+', VmStreamMeta::userFacingMode($path, VmFs::handleMode($handle)));
        VmFs::fclose($handle);
        unlink($path);
    }

    public function testMemoryStreamMapsReadPlusToWritePlusBinary(): void
    {
        $handle = VmFs::fopen('php://memory', 'r+');
        $this->assertNotFalse($handle);
        $this->assertSame('w+b', StreamModeJitHelper::modeForHandle($handle));
        VmFs::fclose($handle);
    }
}
