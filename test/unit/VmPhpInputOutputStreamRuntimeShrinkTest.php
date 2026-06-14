<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmPhpInputOutputStream;
use PHPUnit\Framework\TestCase;

/** VM php://input/php://output must not delegate to host @fopen (#8492, php-in-php). */
final class VmPhpInputOutputStreamRuntimeShrinkTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('REQUEST_BODY=hello-body');
        $_ENV['REQUEST_BODY'] = 'hello-body';
    }

    protected function tearDown(): void
    {
        putenv('REQUEST_BODY');
        unset($_ENV['REQUEST_BODY']);
    }

    public function testVmFsFopenDoesNotUseHostFopenForInputOrOutput(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFs.php');
        $this->assertStringContainsString('VmPhpInputOutputStream::isSupportedUri', $source);
        $this->assertStringContainsString('VmPhpInputOutputStream::open', $source);
        $this->assertStringNotContainsString("@fopen('php://input'", $source);
        $this->assertStringNotContainsString("@fopen('php://output'", $source);
    }

    public function testInputStreamReadsRequestBody(): void
    {
        $handle = VmPhpInputOutputStream::open('php://input', 'r');
        $this->assertNotFalse($handle);
        $this->assertSame('hello-body', VmPhpInputOutputStream::streamGetContents($handle));
        VmPhpInputOutputStream::close($handle);
    }

    public function testVmFsInputOutputRoundTrip(): void
    {
        $in = VmFs::fopen('php://input', 'r');
        $this->assertNotFalse($in);
        $this->assertSame('hello-body', VmFs::fread($in, 100));
        VmFs::fclose($in);

        ob_start();
        $out = VmFs::fopen('php://output', 'w');
        $this->assertNotFalse($out);
        $this->assertSame(4, VmFs::fwrite($out, 'test'));
        VmFs::fclose($out);
        $this->assertSame('test', ob_get_clean());
    }
}
