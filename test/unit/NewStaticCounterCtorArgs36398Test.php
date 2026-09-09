<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * `new C(f(), g())` with side-effecting f/g must not re-emit producers (#36398).
 *
 * php-src: Zend/zend_compile.c zend_compile_new / ZEND_SEND_*.
 */
final class NewStaticCounterCtorArgs36398Test extends \PHPUnit\Framework\TestCase
{
    private const EXPECT = "1|2\n";

    public function testStaticCounterCtorArgsMatchZendOnVmAndAot(): void
    {
        $path = __DIR__.'/../repro/new_static_counter_ctor_args_36398.php';
        $zend = $this->runZendFile($path);
        $this->assertSame(self::EXPECT, $zend, 'Zend oracle');

        $runtime = new Runtime();
        ob_start();
        $runtime->run($runtime->parseAndCompileFile($path));
        $this->assertSame(self::EXPECT, ob_get_clean(), 'VM must match Zend');

        $this->assertSame(self::EXPECT, $this->compileAndRunAot($path), 'AOT must match Zend');
    }

    public function testOpcodeStreamHasTwoProducerExecReturnsBeforeNew(): void
    {
        $path = __DIR__.'/../repro/new_static_counter_ctor_args_36398.php';
        $runtime = new Runtime();
        $block = $runtime->parseAndCompileFile($path);
        $map = [];
        $ref = new \ReflectionClass(OpCode::class);
        foreach ($ref->getConstants() as $name => $val) {
            if (\is_int($val) && str_starts_with($name, 'TYPE_')) {
                $map[$val] = $name;
            }
        }
        $producerExec = 0;
        $sawNew = false;
        foreach ($block->opCodes as $op) {
            $tn = $map[$op->type] ?? '';
            if ($tn === 'TYPE_NEW') {
                $sawNew = true;
                break;
            }
            if ($tn === 'TYPE_FUNCCALL_EXEC_RETURN') {
                ++$producerExec;
            }
        }
        $this->assertTrue($sawNew, 'expected TYPE_NEW in main');
        $this->assertSame(2, $producerExec, 'exactly two mk producers before NEW');
    }

    private function runZendFile(string $path): string
    {
        $php = getenv('PHP_8_2') ?: (getenv('PHP_BINARY') ?: PHP_BINARY);
        $out = shell_exec(escapeshellarg($php).' '.escapeshellarg($path).' 2>&1');

        return \is_string($out) ? $out : '';
    }

    private function compileAndRunAot(string $path): string
    {
        $bin = tempnam(sys_get_temp_dir(), 'aot36398_');
        $this->assertNotFalse($bin);
        @unlink($bin);
        $compile = 'php '.escapeshellarg(dirname(__DIR__, 2).'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
        exec($compile, $clog, $crc);
        $this->assertSame(0, $crc, "compile failed:\n".implode("\n", $clog));
        $this->assertFileExists($bin);
        try {
            exec(escapeshellarg($bin).' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, "aot run failed:\n".implode("\n", $out));

            return implode("\n", $out).([] !== $out ? "\n" : '');
        } finally {
            @unlink($bin);
        }
    }
}
