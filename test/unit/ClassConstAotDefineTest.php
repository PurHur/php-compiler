<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * Guard: AOT must echo compile-time-folded class constants (#16378, #2215).
 *
 * @group llvm
 */
final class ClassConstAotDefineTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $this->markTestSkipped('LLVM 9 required for AOT class const guard');
        }
    }

    public function testUntypedClassConstAotFixture(): void
    {
        $this->runAotFixture('class_const_fetch_user.phpt', '200');
    }

    public function testTypedClassConstAotFixture(): void
    {
        if (!CompilerVersion::supportsTypedClassConstants()) {
            putenv('PHP_COMPILER_PROFILE=8.3');
        }
        $this->runAotFixture('typed_class_const_aot.phpt', "abc\n2\n");
    }

    private function runAotFixture(string $fixture, string $expect): void
    {
        $path = $this->repoRoot.'/test/fixtures/aot/cases/'.$fixture;
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents);
        if (!preg_match('/--FILE--\s*\n(.*?)\n--(?:ENV|EXPECT)/s', $contents, $matches)) {
            $this->fail($fixture.' FILE section missing');
        }
        $tmp = sys_get_temp_dir().'/phpc_class_const_aot_'.md5($fixture).'.php';
        file_put_contents($tmp, $matches[1]);
        $out = sys_get_temp_dir().'/phpc_class_const_aot_'.md5($fixture);
        $compile = shell_exec(
            'PHP_COMPILER_PROFILE=8.3 php '.$this->repoRoot.'/bin/compile.php -o '
            .escapeshellarg($out).' '.escapeshellarg($tmp).' 2>&1'
        );
        $this->assertFileExists($out, 'compile failed: '.$compile);
        $run = shell_exec(escapeshellarg($out).' 2>&1');
        @unlink($tmp);
        @unlink($out);
        $this->assertSame($expect, $run);
    }
}
