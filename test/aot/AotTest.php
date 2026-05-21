<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__ . '/../BaseTest.php';

/**
 * End-to-end AOT tests: compile PHP to a native binary via LLVM and run it.
 *
 * @group llvm
 * @group aot
 */
final class AotTest extends BaseTest
{
    protected static string $DIR = __DIR__ . '/../fixtures/aot';

    /**
     * CGI vars read at AOT runtime via __superglobals__refresh; unset during compile
     * so PHP/LLVM is not blocked or slowed by CONTENT_LENGTH-style environ (issue #314).
     *
     * @var list<string>
     */
    private const COMPILE_EXCLUDED_ENV = [
        'CONTENT_LENGTH',
        'CONTENT_TYPE',
        'REQUEST_BODY',
    ];

    private static ?bool $llvmReady = null;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__ . '/../../bin/compile.php');
        if (!self::isLlvmReady()) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run script/install-llvm9.sh from the repository root.'
            );
        }
    }

    private static function isLlvmReady(): bool
    {
        if (null !== self::$llvmReady) {
            return self::$llvmReady;
        }
        self::$llvmReady = LlvmToolchain::isReady(dirname(__DIR__, 2));

        return self::$llvmReady;
    }

    /**
     * @dataProvider providePHPTests
     */
    public function testCases(string $name, string $code, array $sections): void
    {
        $tmpBase = tempnam(sys_get_temp_dir(), 'phpc_aot_');
        if (false === $tmpBase) {
            $this->fail('Could not create temp file');
        }
        unlink($tmpBase);
        $outfile = $tmpBase;

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $repoRoot = dirname(__DIR__, 2);
        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        LlvmToolchain::applyProcessEnv($env, $repoRoot);
        self::applyEnvSection($env, $sections);
        PhptWebSections::applyToEnv($env, $sections);
        $runEnv = $env;
        $compileEnv = $env;
        foreach (self::COMPILE_EXCLUDED_ENV as $exclude) {
            unset($compileEnv[$exclude]);
        }

        $compileArgv = [$this->BIN, '-o', $outfile];
        if (isset($sections['ENV'])) {
            foreach (explode("\n", trim($sections['ENV'])) as $line) {
                $line = trim($line);
                if ('' === $line) {
                    continue;
                }
                $parts = explode('=', $line, 2);
                if (2 !== count($parts)) {
                    continue;
                }
                if ('QUERY_STRING' === $parts[0]) {
                    $compileArgv[] = '-q';
                    $compileArgv[] = $parts[1];
                }
                if ('REQUEST_BODY' === $parts[0] && '' !== $parts[1]) {
                    $compileArgv[] = '-p';
                    $compileArgv[] = $parts[1];
                }
            }
        }
        $compileArgv = array_merge($compileArgv, PhptWebSections::compileArgvFlags($sections));

        $compile = proc_open(
            array_merge(self::llvmEnvPrefix(), $this->phpCommand(), $compileArgv),
            $descriptorSpec,
            $pipes,
            $repoRoot,
            $compileEnv
        );
        fwrite($pipes[0], $code);
        fclose($pipes[0]);
        $compileErr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($compile);
        if (!is_executable($outfile)) {
            $this->fail(
                "AOT compile did not produce executable {$outfile}: "
                . trim($compileErr !== false ? $compileErr : '')
            );
        }

        $runArgv = [$outfile];
        $runEnvLines = PhptWebSections::envLinesFromSections($sections);
        if (isset($sections['ENV'])) {
            foreach (explode("\n", trim($sections['ENV'])) as $line) {
                $line = trim($line);
                if ('' !== $line) {
                    $runEnvLines[] = $line;
                }
            }
        }
        if ([] !== $runEnvLines) {
            $runArgv = array_merge(self::llvmEnvPrefix(), $runArgv);
            foreach ($runEnvLines as $line) {
                array_splice($runArgv, -1, 0, [$line]);
            }
        }
        $run = proc_open(
            $runArgv,
            $descriptorSpec,
            $runPipes,
            $repoRoot,
            $runEnv
        );
        $result = stream_get_contents($runPipes[1]);
        fclose($runPipes[0]);
        fclose($runPipes[1]);
        fclose($runPipes[2]);
        $exitCode = proc_close($run);
        @unlink($outfile);

        if (isset($sections['EXPECT_EXIT'])) {
            $this->assertSame((int) trim($sections['EXPECT_EXIT']), $exitCode);
        }
        $this->assertExpect($result !== false ? $result : '', $sections);
    }

}
