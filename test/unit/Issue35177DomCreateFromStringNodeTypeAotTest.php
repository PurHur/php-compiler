<?php

declare(strict_types=1);

namespace PHPCompiler\Test;

use PHPUnit\Framework\TestCase;

/**
 * #35177 — AOT Dom\ createFromString nodeType matches VM (no empty/SIGSEGV).
 *
 * @group llvm
 */
final class Issue35177DomCreateFromStringNodeTypeAotTest extends TestCase
{
    public function testAotCreateFromStringNodeTypeMatchesVm(): void
    {
        $previous = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            if (!\PHPCompiler\CompilerVersion::supportsDomLivingStandardNamespace()) {
                self::markTestSkipped('Dom\\ living API needs PHP_COMPILER_PROFILE=8.4');
            }

            $src = dirname(__DIR__).'/repro/issue_dom_createfromstring_nodetype_aot.php';
            $this->assertFileExists($src);

            $vm = $this->runVm($src);
            $this->assertStringContainsString('xml_type=9', $vm);
            $this->assertStringContainsString('html_type=9', $vm);

            $bin = sys_get_temp_dir().'/phpc_35177_'.getmypid().'.bin';
            $compile = $this->runCompile($src, $bin);
            $this->assertSame(0, $compile['code'], "AOT compile failed:\n".$compile['out']);
            $aot = $this->runBin($bin);
            @unlink($bin);

            $this->assertSame(0, $aot['code'], "AOT run failed:\n".$aot['out']);
            $this->assertStringContainsString('xml_type=9', $aot['out']);
            $this->assertStringContainsString('html_type=9', $aot['out']);
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
