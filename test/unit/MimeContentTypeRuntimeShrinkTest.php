<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\MimeContentTypeJitHelper;
use PHPUnit\Framework\TestCase;

/** mime_content_type JIT routes through MimeContentTypeJitHelper PHP, not LLVM sniff (#9236). */
final class MimeContentTypeRuntimeShrinkTest extends TestCase
{
    public function testMimeContentTypeJitHelperDelegatesToVmMime(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/MimeContentTypeJitHelper.php');
        $this->assertStringContainsString('VmMime::mimeContentTypeFromPath', $source);
        $this->assertStringNotContainsString('strncmp', $source);
    }

    public function testMimeContentTypeRuntimeUsesJitHelperNotLlvmSniff(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MimeContentTypeRuntime.php');
        $this->assertStringContainsString('MimeContentTypeJitHelper', $source);
        $this->assertStringNotContainsString("lookupFunction('strncmp')", $source);
        $this->assertStringNotContainsString('__compiler_file_get_contents', $source);
        $this->assertStringNotContainsString('literalString', $source);
        $lineCount = \substr_count($source, "\n") + 1;
        $this->assertLessThan(120, $lineCount);
        $this->assertGreaterThan(60, 186 - $lineCount);
    }

    public function testMimeContentTypeJitHelperMatchesVmMimeSemantics(): void
    {
        $tmp = \tempnam(\sys_get_temp_dir(), 'phpc-mime-');
        $this->assertNotFalse($tmp);
        $path = $tmp.'.php';
        \rename($tmp, $path);
        \file_put_contents($path, "<?php echo 1;\n");

        $this->assertSame('text/x-php', MimeContentTypeJitHelper::mimeContentType($path));
        $this->assertNull(MimeContentTypeJitHelper::mimeContentType('/no/such/phpc-mime-'.bin2hex(random_bytes(4))));

        @\unlink($path);
    }
}
