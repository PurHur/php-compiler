<?php

declare(strict_types=1);

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Spine AOT compiles includes as separate units in require order.
 * ext/gd/VmGd.php sorts before VmGdGd.php, so VmGdGd::CONST defaults are not
 * foldable yet (#3803) and abort honest Zend gen-0 refresh (#22642 r18).
 */
final class VmGdGd2LiteralDefaultsTest extends TestCase
{
    public function testGd2ParamDefaultsAreLiteralsMatchingVmGdGdConstants(): void
    {
        $root = dirname(__DIR__, 2);
        $vmGd = (string) file_get_contents($root.'/ext/gd/VmGd.php');
        $this->assertStringNotContainsString(
            'int $chunkSize = VmGdGd::GD2_CHUNKSIZE',
            $vmGd,
            'cross-class default fails when VmGd compiles before VmGdGd (#22642)'
        );
        $this->assertStringNotContainsString(
            'int $mode = VmGdGd::GD2_FMT_RAW',
            $vmGd
        );
        $this->assertSame(128, \PHPCompiler\ext\gd\VmGdGd::GD2_CHUNKSIZE);
        $this->assertSame(1, \PHPCompiler\ext\gd\VmGdGd::GD2_FMT_RAW);
        $this->assertMatchesRegularExpression(
            '/int \$chunkSize = 128,\s*\/\/ VmGdGd::GD2_CHUNKSIZE/',
            $vmGd
        );
        $this->assertMatchesRegularExpression(
            '/int \$mode = 1\s*\/\/ VmGdGd::GD2_FMT_RAW/',
            $vmGd
        );
    }

    public function testVmGdParsesAloneUnderAotRuntime(): void
    {
        require_once dirname(__DIR__, 2).'/lib/Runtime.php';
        $rt = new Runtime(Runtime::MODE_AOT);
        $block = $rt->parseAndCompileFile(dirname(__DIR__, 2).'/ext/gd/VmGd.php');
        $this->assertNotNull($block);
    }
}
