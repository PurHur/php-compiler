<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit\Web;

use PHPCompiler\ext\standard\VmFastCgi;
use PHPCompiler\VM\OutputBuffer;
use PHPCompiler\Web\CgiDriver;
use PHPCompiler\Web\ResponseContext;
use PHPUnit\Framework\TestCase;

final class FastCgiFinishRequestTest extends TestCase
{
    private ?string $scriptPath = null;

    protected function tearDown(): void
    {
        VmFastCgi::clearFastCgiRequestActive();
        OutputBuffer::reset();
        ResponseContext::reset();
        if (null !== $this->scriptPath && is_file($this->scriptPath)) {
            @unlink($this->scriptPath);
        }
        $this->scriptPath = null;
    }

    public function testFinishRequestReturnsFalseWhenNotFastCgi(): void
    {
        VmFastCgi::clearFastCgiRequestActive();
        self::assertFalse(VmFastCgi::finishRequest());
    }

    public function testFinishRequestReturnsTrueAndFlushesWhenFastCgiActive(): void
    {
        VmFastCgi::markFastCgiRequestActive();
        OutputBuffer::start();
        OutputBuffer::append('buffered');
        ob_start();
        try {
            self::assertTrue(VmFastCgi::finishRequest());
        } finally {
            ob_end_clean();
        }
        self::assertSame(0, OutputBuffer::getLevel());
        self::assertTrue(ResponseContext::isFastCgiRequestFinished());
    }

    /** Post-finish echo must not appear in the CGI client body (#6136). */
    public function testCgiDriverBodyExcludesOutputAfterFinishRequest(): void
    {
        VmFastCgi::markFastCgiRequestActive();
        $this->scriptPath = tempnam(sys_get_temp_dir(), 'fcgi6136_');
        self::assertNotFalse($this->scriptPath);
        $php = $this->scriptPath.'.php';
        @unlink($this->scriptPath);
        $this->scriptPath = $php;
        file_put_contents($php, <<<'PHP'
<?php
header('Content-Type: text/plain');
echo "body\n";
$ok = fastcgi_finish_request();
echo "after\n";
PHP);

        putenv('GATEWAY_INTERFACE=CGI/1.1');
        $_ENV['GATEWAY_INTERFACE'] = 'CGI/1.1';
        $_SERVER['GATEWAY_INTERFACE'] = 'CGI/1.1';
        try {
            [$status, $contentType, $body] = CgiDriver::runVmScript($php);
        } finally {
            putenv('GATEWAY_INTERFACE');
            unset($_ENV['GATEWAY_INTERFACE'], $_SERVER['GATEWAY_INTERFACE']);
        }

        self::assertSame(200, $status > 0 ? $status : 200);
        self::assertStringContainsString('text/plain', $contentType);
        self::assertSame("body\n", $body);
        self::assertStringNotContainsString('after', $body);
    }

    public function testFinishRequestSnapshotsHostObContents(): void
    {
        VmFastCgi::markFastCgiRequestActive();
        ResponseContext::reset();
        ob_start();
        try {
            echo "pre\n";
            self::assertTrue(VmFastCgi::finishRequest());
            echo "post\n";
            self::assertSame("pre\n", ResponseContext::getFastCgiFinishedBody());
            self::assertTrue(ResponseContext::isFastCgiRequestFinished());
        } finally {
            ob_end_clean();
        }
    }
}
