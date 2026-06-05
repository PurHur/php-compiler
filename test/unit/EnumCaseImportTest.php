<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;
use PHPCompiler\Compiler\CompileFatal;

/** Enum case import via use Enum\Case (#6219). */
final class EnumCaseImportTest extends TestCase
{
    private const CODE = <<<'PHP'
namespace App {
    enum Status: string {
        case Ok = 'ok';
    }
    use Status\Ok;
    echo Ok->name;
}
PHP;

    public function testVmEnumCaseImport(): void
    {
        $rt = new Runtime();
        $block = $rt->parseAndCompile("<?php\n".self::CODE, 'enum_case_import.php');
        ob_start();
        $rt->run($block);
        $this->assertSame('Ok', ob_get_clean());
    }

    public function testMissingEnumCaseImportIsCompileFatal(): void
    {
        $code = <<<'PHP'
namespace App {
    enum Status: string { case Ok = 'ok'; }
    use Status\Missing;
}
PHP;
        $rt = new Runtime();
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage('Case Missing not found in enum App\Status');
        $rt->parseAndCompile("<?php\n".$code, 'enum_case_import_bad.php');
    }

    /**
     * @group llvm
     */
    public function testAotEnumCaseImport(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->assertSame('Ok', $this->runAotBinary());
    }

    private function runAotBinary(): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_enum_case_import_');
        $out = $tmp.'_bin';
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n".self::CODE);
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $repo);
        $compile = proc_open(
            [PHP_BINARY, $repo.'/bin/compile.php', '-o', $out, $tmp],
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
        $run = proc_open(
            [$out],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo,
            $env
        );
        $this->assertIsResource($run);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $runErr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($run), trim((string) $runErr));
        @unlink($tmp);
        @unlink($out);

        return $stdout !== false ? rtrim($stdout) : '';
    }
}
