<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmMime;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** Issue #7865 / #19203: VM mime_content_type must not delegate to host mime or stream APIs. */
final class VmMimeTest extends TestCase
{
    public function testVmMimeDoesNotReferenceHostMimeContentType(): void
    {
        $source = file_get_contents(__DIR__.'/../../ext/standard/VmMime.php');
        $this->assertIsString($source);
        $this->assertStringNotContainsString('function_exists(\'mime_content_type\')', $source);
        $this->assertStringNotContainsString('\\mime_content_type(', $source);
        $this->assertStringNotContainsString('\\ftell(', $source);
        $this->assertStringNotContainsString('\\stream_get_contents(', $source);
        $this->assertStringNotContainsString('\\fseek(', $source);
        $this->assertStringNotContainsString('lookupResource(', $source);
    }

    public function testMimeContentTypeFromPhpMemoryStream(): void
    {
        $handle = VmFs::fopen('php://memory', 'w+');
        $this->assertNotFalse($handle);
        VmFs::fwrite($handle, '<?php echo 1;');
        VmFs::rewind($handle);
        $var = new Variable();
        $var->streamHandle($handle);
        $this->assertSame('text/x-php', VmMime::mimeContentType($var));
        VmFs::fclose($handle);
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

    public function testDetectFromBytesHtml(): void
    {
        $this->assertSame('text/html', VmMime::detectFromBytes("<html><body></body></html>\n"));
        $this->assertSame('text/html', VmMime::detectFromBytes("<!DOCTYPE html>\n<html></html>\n"));
    }

    /** Issue #19353: XML PI / JSON / SVG MIME sniff. */
    public function testDetectFromBytesXmlJsonSvg(): void
    {
        $this->assertSame('text/xml', VmMime::detectFromBytes('<?xml version="1.0"?><a/>'));
        $this->assertSame('application/json', VmMime::detectFromBytes('{"a":1}'));
        $this->assertSame('application/json', VmMime::detectFromBytes('  [1,2]'));
        $this->assertSame('image/svg+xml', VmMime::detectFromBytes('<svg xmlns="http://www.w3.org/2000/svg"/>'));
        // Leading whitespace before <?xml is plain; BOM+JSON is plain (libmagic).
        $this->assertSame('text/plain', VmMime::detectFromBytes('  <?xml version="1.0"?><a/>'));
        $this->assertSame('text/plain', VmMime::detectFromBytes("\xef\xbb\xbf{\"a\":1}"));
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
