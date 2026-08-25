<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: fopen + echo must not abort at exit — fflush loads FILE* from @stdout (#34737).
 *
 * @see php-src main/output.c php_output_flush
 *
 * @group llvm
 * @group aot
 */
final class FopenEchoFflush34737AotTest extends TestCase
{
    public function testVmFopenEcho(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_34737_fopen_echo_fflush.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34737_fopen_echo_fflush.php'));
        $out = (string) ob_get_clean();
        $this->assertSame("ok\n", $out);
    }

    /**
     * @dataProvider aotFopenEchoCases
     */
    public function testAotFopenEchoExitsZero(string $label, string $src): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $srcFile = sys_get_temp_dir().'/phpc_aot_fopen_echo_34737_'.$label.'_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_aot_fopen_echo_34737_'.$label.'_'.getmypid().'.bin';
        file_put_contents($srcFile, $src);
        try {
            $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
                .escapeshellarg($root.'/bin/compile.php')
                .' -o '.escapeshellarg($bin).' '.escapeshellarg($srcFile).' 2>&1';
            exec($compile, $compileOut, $compileRc);
            $this->assertSame(0, $compileRc, implode("\n", $compileOut));
            $this->assertFileExists($bin);

            $matched = 0;
            $lastOut = '';
            $lastRc = -1;
            for ($i = 0; $i < 3; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $lastOut = implode("\n", $runOut);
                $lastRc = $runRc;
                if ($runRc === 0 && $lastOut === 'ok') {
                    ++$matched;
                }
            }
            $this->assertSame(
                3,
                $matched,
                "expected ok/rc=0 on 3/3 runs ({$label}); last rc={$lastRc} out=".var_export($lastOut, true)
            );
        } finally {
            @unlink($srcFile);
            @unlink($bin);
        }
    }

    /** @return array<string, array{string, string}> */
    public static function aotFopenEchoCases(): array
    {
        return [
            'php_memory' => [
                'memory',
                "<?php \$f=fopen('php://memory','r+'); echo \"ok\\n\";\n",
            ],
            'php_temp' => [
                'temp',
                "<?php \$f=fopen('php://temp','r+'); echo \"ok\\n\";\n",
            ],
            'plain_file' => [
                'file',
                "<?php \$p=sys_get_temp_dir().'/phpc_34737_'.getmypid().'.txt'; \$f=fopen(\$p,'w'); echo \"ok\\n\"; @unlink(\$p);\n",
            ],
        ];
    }
}
