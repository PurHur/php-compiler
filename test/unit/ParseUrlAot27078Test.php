<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Thin AOT execute guard for parse_url() assoc string fields (#27078).
 *
 * php-src: ext/standard/url.c — PHP_FUNCTION(parse_url)
 *
 * @group llvm
 * @group aot
 */
final class ParseUrlAot27078Test extends TestCase
{
    public function testAotParseUrlAssocStringsMatchZendShape(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_parse_url_27078_'.getmypid().'.php';
        file_put_contents($src, <<<'PHP'
<?php
$lit = json_encode(parse_url("https://ex.com/a?b=1"));
$u = "https" . "://ex.com:8080/a?b=1#f";
$rt = json_encode(parse_url($u));
$scheme = parse_url($u, PHP_URL_SCHEME);
$port = parse_url($u, PHP_URL_PORT);
echo $lit, "\n", $rt, "\n", $scheme, "|", $port, "\n";
PHP);
        $bin = sys_get_temp_dir().'/phpc_parse_url_27078_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        $expectedLit = '{"scheme":"https","host":"ex.com","path":"\/a","query":"b=1"}';
        $expectedRt = '{"scheme":"https","host":"ex.com","port":8080,"path":"\/a","query":"b=1","fragment":"f"}';
        try {
            for ($i = 0; $i < 10; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $this->assertSame(
                    $expectedLit."\n".$expectedRt."\n"."https|8080\n",
                    implode("\n", $runOut)."\n",
                    'run '.($i + 1)
                );
            }
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
