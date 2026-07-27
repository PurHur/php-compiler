<?php

declare(strict_types=1);

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Spine AOT compiles includes as separate units. Host ZLIB_ENCODING_* consts are not
 * foldable as param defaults yet (#3803) and abort honest Zend gen-0 refresh (#23468).
 *
 * Literals landed in #23646; this guard prevents regression.
 */
final class VmZlibLiteralDefaultsTest extends TestCase
{
    public function testGzParamEncodingDefaultsAreLiteralsMatchingZlibConstants(): void
    {
        $root = dirname(__DIR__, 2);
        $src = (string) file_get_contents($root.'/ext/standard/VmZlib.php');
        $this->assertStringNotContainsString(
            'int $encoding = \\ZLIB_ENCODING_',
            $src,
            'host ZLIB_ENCODING_* defaults fail spine AOT (#3803 / #23468)'
        );
        $this->assertSame(15, \ZLIB_ENCODING_DEFLATE);
        $this->assertSame(-15, \ZLIB_ENCODING_RAW);
        $this->assertSame(31, \ZLIB_ENCODING_GZIP);
        $this->assertMatchesRegularExpression(
            '/int \$encoding = 15\s*\/\/\s*ZLIB_ENCODING_DEFLATE/',
            $src
        );
        $this->assertMatchesRegularExpression(
            '/int \$encoding = -15\s*\/\/\s*ZLIB_ENCODING_RAW/',
            $src
        );
        $this->assertMatchesRegularExpression(
            '/int \$encoding = 31\s*\/\/\s*ZLIB_ENCODING_GZIP/',
            $src
        );
    }

    public function testVmZlibParsesAloneUnderAotRuntime(): void
    {
        require_once dirname(__DIR__, 2).'/lib/Runtime.php';
        $rt = new Runtime(Runtime::MODE_AOT);
        $block = $rt->parseAndCompileFile(dirname(__DIR__, 2).'/ext/standard/VmZlib.php');
        $this->assertNotNull($block);
    }
}
