<?php

declare(strict_types=1);

namespace PHPCompiler\Test;

use PHPUnit\Framework\TestCase;

/**
 * AOT Dom\HTMLDocument/XMLDocument::createFromFile leftover of #27300/#27108.
 *
 * @group llvm
 */
final class DomCreateFromFileAotTest extends TestCase
{
    public function testAotCreateFromFileMatchesVm(): void
    {
        $previous = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            if (!\PHPCompiler\CompilerVersion::supportsDomLivingStandardNamespace()) {
                self::markTestSkipped('Dom\\ living API needs PHP_COMPILER_PROFILE=8.4');
            }

            $src = dirname(__DIR__).'/repro/aot_dom_html_createfromfile.php';
            $this->assertFileExists($src);

            $vm = $this->runVm($src);
            $this->assertStringContainsString('Dom\\HTMLDocument', $vm);
            $this->assertStringContainsString('Dom\\XMLDocument', $vm);
            $this->assertStringContainsString("root\n", $vm);
            $this->assertStringNotContainsString("null\n", $vm);

            $bin = sys_get_temp_dir().'/phpc_cff_'.getmypid().'.bin';
            $compile = $this->runCompile($src, $bin);
            $this->assertSame(0, $compile['code'], "AOT compile failed:\n".$compile['out']);
            $aot = $this->runBin($bin);
            @unlink($bin);

            $this->assertSame(0, $aot['code'], "AOT run failed:\n".$aot['out']);
            $this->assertSame($vm, $aot['out']);
        } finally {
            if (false === $previous) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$previous);
            }
        }
    }

    private function runVm(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $cmd = 'env PHP_COMPILER_PROFILE=8.4 php '.escapeshellarg($root.'/bin/vm.php')
            .' '.escapeshellarg($src).' 2>&1';
        exec($cmd, $lines, $code);
        $this->assertSame(0, $code, "VM failed:\n".implode("\n", $lines));

        return implode("\n", $lines)."\n";
    }

    /** @return array{code:int,out:string} */
    private function runCompile(string $src, string $bin): array
    {
        $root = dirname(__DIR__, 2);
        $cmd = 'env PHP_COMPILER_PROFILE=8.4 php '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($cmd, $lines, $code);

        return ['code' => $code, 'out' => implode("\n", $lines)];
    }

    /** @return array{code:int,out:string} */
    private function runBin(string $bin): array
    {
        exec(escapeshellarg($bin).' 2>&1', $lines, $code);

        return ['code' => $code, 'out' => implode("\n", $lines).([] === $lines ? '' : "\n")];
    }
}
