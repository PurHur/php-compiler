<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** set_error_handler() / restore_error_handler() VM smoke (issue #1379). */
final class ErrorHandlerBuiltinTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }

    public static function providePHPTests(): \Generator
    {
        yield 'set_error_handler.phpt' => self::parsePHPT(
            __DIR__.'/../compliance/cases/stdlib/set_error_handler.phpt',
            'set_error_handler.phpt'
        );
        yield 'set_error_handler_closure.phpt' => self::parsePHPT(
            __DIR__.'/../compliance/cases/stdlib/set_error_handler_closure.phpt',
            'set_error_handler_closure.phpt'
        );
        yield 'restore_error_handler.phpt' => self::parsePHPT(
            __DIR__.'/../compliance/cases/stdlib/restore_error_handler.phpt',
            'restore_error_handler.phpt'
        );
        yield 'restore_error_handler_empty_stack.phpt' => self::parsePHPT(
            __DIR__.'/../compliance/cases/stdlib/restore_error_handler_empty_stack.phpt',
            'restore_error_handler_empty_stack.phpt'
        );
    }

    public function testVmSetErrorHandlerReturnsPrevious(): void
    {
        $code = <<<'PHP'
function h($errno, $errstr, $errfile, $errline) {
    return true;
}
$prev = set_error_handler('h');
echo $prev === null ? "null\n" : "other\n";
restore_error_handler();
PHP;
        $this->assertSame("null\n", $this->runInline($code));
    }

    private function runInline(string $code): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_err_vm_');
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
