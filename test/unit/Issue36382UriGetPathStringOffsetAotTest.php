<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: Nyholm Uri::getPath() if/elseif string offsets on a VALUE-boxed local (#36382).
 *
 * isset($path[1]) on a refcounted string type byte must not take the hashtable arm
 * (unmasked VM TYPE_STRING compare) and clobber the string to [].
 *
 * @see php-src Zend/zend_execute.c zend_isset_dim
 * @see test/repro/issue_36382_uri_getpath_offsets.php
 *
 * @group llvm
 * @group aot
 */
final class Issue36382UriGetPathStringOffsetAotTest extends TestCase
{
    private const EXPECTED = "/hello\nempty:[]\n\n";

    public function testVmMatchesExpected(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_36382_uri_getpath_offsets.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_36382_uri_getpath_offsets.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_36382_uri_getpath_offsets.php';
        $bin = sys_get_temp_dir().'/phpc_issue_36382_getpath_'.getmypid().'.bin';
        $compile = @shell_exec(
            'cd '.escapeshellarg($root).' && php bin/compile.php --no-cache -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1'
        );
        $this->assertFileExists($bin, 'compile failed: '.(string) $compile);
        $out = (string) shell_exec(escapeshellarg($bin).' 2>&1');
        @unlink($bin);
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotIfElseifValueBoxedLocalNotClobbered(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_36382_string_offset_elseif_isset.php';
        $bin = sys_get_temp_dir().'/phpc_issue_36382_elseif_'.getmypid().'.bin';
        $compile = @shell_exec(
            'cd '.escapeshellarg($root).' && php bin/compile.php --no-cache -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1'
        );
        $this->assertFileExists($bin, 'compile failed: '.(string) $compile);
        $out = (string) shell_exec(escapeshellarg($bin).' 2>&1');
        @unlink($bin);
        $this->assertSame("keep\nstring(6) \"/hello\"\n", $out);
    }
}
