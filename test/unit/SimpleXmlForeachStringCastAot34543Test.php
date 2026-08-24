<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: SimpleXMLElement children()/attributes() foreach (string) cast (#34543 / re-#27535).
 *
 * php-src: ext/simplexml/sxe.c — iterator / cast_object
 *
 * @group llvm
 * @group aot
 */
final class SimpleXmlForeachStringCastAot34543Test extends TestCase
{
    public function testAotChildrenForeachStringCastMatchesZend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_27535_sxe_children_foreach_aot.php';
        $this->assertFileExists($src);
        $this->assertAotMatches($src, "a:1;b:2;");
    }

    public function testAotAttributesForeachMatchesZend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_sxe_attr_34543_'.getmypid().'.php';
        file_put_contents(
            $src,
            "<?php\n"
            ."\$xml = simplexml_load_string('<r a=\"1\" b=\"2\"/>');\n"
            ."foreach (\$xml->attributes() as \$k => \$v) {\n"
            ."    echo \"\$k:\$v;\";\n"
            ."}\n"
            ."echo \"\\n\";\n"
        );
        try {
            $this->assertAotMatches($src, "a:1;b:2;");
        } finally {
            @unlink($src);
        }
    }

    private function assertAotMatches(string $src, string $expected): void
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/phpc_sxe_34543_'.getmypid().'_'.mt_rand(1000, 9999);
        $compile = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $cout, $crc);
        $this->assertSame(0, $crc, "AOT compile failed:\n".implode("\n", $cout));
        $this->assertFileExists($bin);

        try {
            $aot = [];
            exec(escapeshellarg($bin).' 2>&1', $aot, $arc);
            $this->assertSame(0, $arc, "AOT run rc=$arc out=".implode("\n", $aot));
            $this->assertSame($expected, rtrim(implode("\n", $aot)));
        } finally {
            @unlink($bin);
        }
    }
}
