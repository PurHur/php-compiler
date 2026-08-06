<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\LlvmToolchain;
use PHPUnit\Framework\TestCase;

/** date_interval_create_from_date_string() + DateInterval::createFromDateString() VM + AOT (#4606, #9993). */
final class DateIntervalCreateFromDateStringTest extends TestCase
{
    private const CODE_VM_PROCEDURAL = <<<'PHP'
$iv = date_interval_create_from_date_string('1 day');
echo ($iv instanceof DateInterval) ? 'ok' : 'bad', "\n";
echo $iv->format('%d'), "\n";
$combo = date_interval_create_from_date_string('1 day 2 hours');
echo $combo->d, ':', $combo->h, "\n";
$plus = date_interval_create_from_date_string('1 day + 2 hours');
echo $plus->d, ':', $plus->h, "\n";
$minus = date_interval_create_from_date_string('1 day - 2 hours');
echo $minus->d, ':', $minus->h, "\n";
PHP;

    private const CODE_VM_STATIC = <<<'PHP'
$iv = DateInterval::createFromDateString('1 day');
echo ($iv instanceof DateInterval) ? 'ok' : 'bad', "\n";
echo $iv->format('%d'), "\n";
$combo = DateInterval::createFromDateString('1 day 2 hours');
echo $combo->d, ':', $combo->h, "\n";
$plus = DateInterval::createFromDateString('1 day + 2 hours');
echo $plus->d, ':', $plus->h, "\n";
$minus = DateInterval::createFromDateString('1 day - 2 hours');
echo $minus->d, ':', $minus->h, "\n";
PHP;

    /**
     * @group llvm
     * @group jit
     */
    public function testAotCompileLoweringProcedural(): void
    {
        $this->assertAotCompileOk('test/fixtures/aot/compile-only/date_interval_create_from_date_string.php');
    }

    /**
     * @group llvm
     * @group jit
     */
    public function testAotCompileLoweringStaticMethod(): void
    {
        $this->assertAotCompileOk('test/fixtures/aot/compile-only/dateinterval_create_from_date_string_static.php');
    }

    public function testVmRelativeIntervalParsingProcedural(): void
    {
        $this->assertSame("ok\n1\n1:2\n1:2\n1:-2\n", $this->runBin('bin/vm.php', self::CODE_VM_PROCEDURAL));
    }

    public function testVmRelativeIntervalParsingStaticMethod(): void
    {
        $this->assertSame("ok\n1\n1:2\n1:2\n1:-2\n", $this->runBin('bin/vm.php', self::CODE_VM_STATIC));
    }

    /** Relative day vocabulary — next/last day, yesterday, tomorrow (#27954). */
    public function testVmRelativeDayWordsCreateFromDateString(): void
    {
        $code = <<<'PHP'
foreach (['next day', 'last day', 'yesterday', 'tomorrow', '1 day', 'previous day', 'this day'] as $s) {
    $i = @DateInterval::createFromDateString($s);
    if ($i === false) {
        echo $s, " => false\n";
    } else {
        echo $s, ' => d=', $i->d, ' invert=', $i->invert, "\n";
    }
}
$fn = @date_interval_create_from_date_string('tomorrow');
echo 'fn tomorrow => d=', $fn->d, ' invert=', $fn->invert, "\n";
PHP;
        $expect = "next day => d=1 invert=0\n"
            ."last day => d=-1 invert=0\n"
            ."yesterday => d=-1 invert=0\n"
            ."tomorrow => d=1 invert=0\n"
            ."1 day => d=1 invert=0\n"
            ."previous day => d=-1 invert=0\n"
            ."this day => d=0 invert=0\n"
            ."fn tomorrow => d=1 invert=0\n";
        $this->assertSame($expect, $this->runBin('bin/vm.php', $code));
    }

    private function assertAotCompileOk(string $relativeTarget): void
    {
        $repo = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($repo)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $target = $repo.'/'.$relativeTarget;
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $repo);
        $compile = proc_open(
            ['php', $repo.'/bin/compile.php', '-l', $target],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo,
            $env
        );
        $this->assertIsResource($compile);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $compileErr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($compile), trim((string) $compileErr));
    }

    private function runBin(string $bin, string $code): string
    {
        $repo = dirname(__DIR__, 2);
        $path = $repo.'/'.$bin;
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_di_create_vm_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n".$code);
        $descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $repo);
        $proc = proc_open(['php', $path, $tmp], $descriptor, $pipes, $repo, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        @unlink($tmp);
        $this->assertSame(0, proc_close($proc));

        return (string) $out;
    }
}
