<?php

declare(strict_types=1);

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Spine AOT cannot fold global ZLIB_ENCODING_* into param defaults yet (#3803).
 * Non-literal defaults abort honest Zend gen-0 refresh at VmZlib (#23468 / #22642).
 */
final class VmZlibLiteralEncodingDefaultsTest extends TestCase
{
    public function testZlibEncodingParamDefaultsAreLiteralsMatchingModuleConstants(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['ext/standard/VmZlib.php', 'ext/standard/VmZlibCore.php'] as $rel) {
            $src = (string) file_get_contents($root.'/'.$rel);
            $this->assertStringNotContainsString(
                '= \\ZLIB_ENCODING_',
                $src,
                $rel.': global ZLIB_ENCODING_* defaults fail spine AOT (#23468)'
            );
            $this->assertMatchesRegularExpression(
                '/int \$encoding = 15\s*\/\/ ZLIB_ENCODING_DEFLATE/',
                $src,
                $rel
            );
            $this->assertMatchesRegularExpression(
                '/int \$encoding = -15\s*\/\/ ZLIB_ENCODING_RAW/',
                $src,
                $rel
            );
            $this->assertMatchesRegularExpression(
                '/int \$encoding = 31\s*\/\/ ZLIB_ENCODING_GZIP/',
                $src,
                $rel
            );
        }
        $this->assertSame(15, \ZLIB_ENCODING_DEFLATE);
        $this->assertSame(-15, \ZLIB_ENCODING_RAW);
        $this->assertSame(31, \ZLIB_ENCODING_GZIP);
    }

    public function testVmZlibParsesAloneUnderAotRuntime(): void
    {
        require_once dirname(__DIR__, 2).'/lib/Runtime.php';
        $rt = new Runtime(Runtime::MODE_AOT);
        $block = $rt->parseAndCompileFile(dirname(__DIR__, 2).'/ext/standard/VmZlib.php');
        $this->assertNotNull($block);
        $blockCore = $rt->parseAndCompileFile(dirname(__DIR__, 2).'/ext/standard/VmZlibCore.php');
        $this->assertNotNull($blockCore);
    }
}
