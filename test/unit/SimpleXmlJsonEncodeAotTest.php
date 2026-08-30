<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: json_encode(SimpleXMLElement) leftover of JsonSerializable object path (#35795 / #35850).
 *
 * php-src: ext/json/json_encoder.c + ext/simplexml/sxe.c get_properties_for
 *
 * @group llvm
 * @group aot
 */
final class SimpleXmlJsonEncodeAotTest extends TestCase
{
    private const EXPECTED = "{\"a\":\"1\"}\n{\"@attributes\":{\"id\":\"1\"},\"a\":[\"x\",\"y\"]}\n";

    public function testVmJsonEncodeMatchesZendShape(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/simplexml_json_encode_aot.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'simplexml_json_encode_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotJsonEncodeMatchesVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/simplexml_json_encode_aot.php';
        $bin = sys_get_temp_dir().'/phpc_sxe_json_'.getmypid().'.bin';
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame(self::EXPECTED, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
