<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** print_r() VM smoke and compliance (issues #1378, #10100). */
final class PrintRBuiltinTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }

    public static function providePHPTests(): \Generator
    {
        yield 'print_r_enum_case.phpt' => self::parsePHPT(
            __DIR__.'/../compliance/cases/stdlib/print_r_enum_case.phpt',
            'print_r_enum_case.phpt'
        );
    }

    public function testVmPrintRBackedAndUnitEnumCases(): void
    {
        $code = <<<'PHP'
declare(strict_types=1);
enum ES: string { case A = 'a'; }
enum EI: int { case A = 1; }
enum PU { case A; }
echo print_r(ES::A, true);
echo print_r(EI::A, true);
echo print_r(PU::A, true);
PHP;
        $expected = <<<'OUT'
ES Enum:string
(
    [name] => A
    [value] => a
)
EI Enum:int
(
    [name] => A
    [value] => 1
)
PU Enum
(
    [name] => A
)

OUT;
        $this->assertSame($expected, $this->runInline($code));
    }

    private function runInline(string $code): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_pr_vm_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n".$code);
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $repo);
        $proc = proc_open(
            [PHP_BINARY, $repo.'/bin/vm.php', $tmp],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo,
            $env
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($proc), $stderr ?: 'VM run failed');
        @unlink($tmp);

        return $stdout;
    }
}
