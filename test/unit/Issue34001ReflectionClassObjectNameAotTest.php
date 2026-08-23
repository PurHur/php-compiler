<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: ReflectionClass / ReflectionObject $name and getName after construct (#34001).
 *
 * @see php-src ext/reflection/php_reflection.c zim_ReflectionClass___construct
 * @see php-src ext/reflection/php_reflection.c zim_ReflectionObject___construct
 *
 * @group llvm
 * @group aot
 */
final class Issue34001ReflectionClassObjectNameAotTest extends TestCase
{
    public function testObjectLayoutUsesValueBoxesAndHasConstructor(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Builtin/Type/Object_.php'
        );
        $this->assertMatchesRegularExpression(
            "/'reflectionclass' === \\\$lcname.*?TYPE_VALUE.*?markHasConstructor/s",
            $source
        );
        $this->assertMatchesRegularExpression(
            "/'reflectionobject' === \\\$lcname.*?setClassParentName.*?markHasConstructor/s",
            $source
        );
    }

    public function testContextRegistersObjectGetName(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Context.php'
        );
        $this->assertStringContainsString(
            "functionProxies['reflectionobject::getname']",
            $source
        );
        $this->assertStringContainsString('#34001', $source);
    }

    public function testAotNameAndGetNameMatchZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34001_reflection_class_object_name.php';
        $bin = sys_get_temp_dir().'/phpc_34001_refl_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $expected = "A|A\nA|A";
            for ($i = 0; $i < 3; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $joined = implode("\n", $runOut);
                $this->assertNotSame(139, $runRc, 'run '.$i.': '.$joined);
                $this->assertSame(0, $runRc, 'run '.$i.': '.$joined);
                $this->assertSame($expected, trim($joined));
            }
        } finally {
            @unlink($bin);
        }
    }
}
