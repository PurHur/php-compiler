<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmMime;
use PHPUnit\Framework\TestCase;

/** Issue #7865: VM mime_content_type must not delegate to host \\mime_content_type(). */
final class VmMimeTest extends TestCase
{
    public function testVmMimeDoesNotReferenceHostMimeContentType(): void
    {
        $source = file_get_contents(__DIR__.'/../../ext/standard/VmMime.php');
        $this->assertIsString($source);
        $this->assertStringNotContainsString('function_exists(\'mime_content_type\')', $source);
        $this->assertStringNotContainsString('\\mime_content_type(', $source);
    }

    public function testDetectFromBytesPhpSource(): void
    {
        $this->assertSame('text/x-php', VmMime::detectFromBytes("<?php echo 1;\n"));
    }

    public function testDetectFromBytesPng(): void
    {
        $this->assertSame('image/png', VmMime::detectFromBytes("\x89PNG\r\n\x1a\n\x00\x00"));
    }

    public function testDetectFromBytesPlainText(): void
    {
        $this->assertSame('text/plain', VmMime::detectFromBytes('not a known format'));
        $this->assertSame('text/plain', VmMime::detectFromBytes("127.0.0.1 localhost\n"));
    }

    public function testDetectFromBytesBinary(): void
    {
        $this->assertSame('application/octet-stream', VmMime::detectFromBytes("\x00binary"));
    }

    public function testDetectFromBytesEmpty(): void
    {
        $this->assertSame('application/x-empty', VmMime::detectFromBytes(''));
    }
}
