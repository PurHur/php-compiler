<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__ . '/../BaseTest.php';

/**
 * End-to-end AOT tests: compile PHP to a native binary via LLVM and run it.
 *
 * @group llvm
 * @group aot
 * @group aot-link
 */
class AotTest extends BaseTest
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

    public static function providePHPTests(): \Generator
    {
        foreach (parent::providePHPTests() as $name => $case) {
            if (!CompilerVersion::supportsStrIncrement()
                && (str_contains($name, 'str_increment') || str_contains($name, 'str_decrement'))) {
                continue;
            }
            if (!CompilerVersion::supportsFpow()
                && (str_contains($name, 'fpow') || str_contains($name, 'fmin') || str_contains($name, 'fmax'))) {
                continue;
            }
            if (!CompilerVersion::supportsZendThreadId() && str_contains($name, 'zend_thread_id')) {
                continue;
            }
            if (!CompilerVersion::supportsGetmygrgid() && str_contains($name, 'getmygrgid')) {
                continue;
            }
            if (!CompilerVersion::supportsStreamSupports()
                && (str_contains($name, 'stream_supports') || str_contains($name, 'stream_meta_seekable'))
                && !str_contains($name, 'stream_supports_lock')) {
                continue;
            }
            if (!CompilerVersion::supportsConvertCyrString()
                && str_contains($name, 'convert_cyr_string')) {
                continue;
            }
            if (!CompilerVersion::supportsStrxfrm()
                && str_contains($name, 'strxfrm')) {
                continue;
            }
            // Pipe operator AOT: enabled after AssertOptionsRuntime CFG fix (#9750).
            // Concat-on-LHS (`"a" . "b" |> f`) remains VM/JIT-only until inline concat-in-call AOT lands.
            yield $name => $case;
        }
    }

    /**
     * @dataProvider providePHPTests
     */
    public function testCases(string $name, string $code, array $sections): void
    {
        $outfile = sys_get_temp_dir().'/phpc_aot_'.bin2hex(random_bytes(8));

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
        self::applyDefaultAotWebEnv($env, $name);
        $bodyFile = null;
        if (isset($sections['POST']) && '' !== $sections['POST']) {
            $bodyFile = tempnam(sys_get_temp_dir(), 'phpc_post_');
            if (false !== $bodyFile) {
                file_put_contents($bodyFile, $sections['POST']);
                $env['REQUEST_BODY_FILE'] = $bodyFile;
                unset($env['REQUEST_BODY']);
            }
        }
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
                // REQUEST_BODY in --ENV-- is for runtime refresh only (issues #291, #314).
            }
        }
        $compileArgv = array_merge($compileArgv, PhptWebSections::compileArgvFlags($sections));
        if (null !== $bodyFile) {
            $stripped = [];
            $skipNext = false;
            foreach ($compileArgv as $arg) {
                if ($skipNext) {
                    $skipNext = false;
                    continue;
                }
                if ('-p' === $arg) {
                    $skipNext = true;
                    continue;
                }
                $stripped[] = $arg;
            }
            $compileArgv = $stripped;
        }
        $includeFile = null;
        $entryFile = null;
        $runfile = isset($sections['RUNFILE']) ? trim($sections['RUNFILE']) : '';
        if ('' !== $runfile) {
            $runPath = realpath(($sections['__phpt_dir'] ?? $repoRoot).'/'.$runfile);
            $this->assertNotFalse($runPath, "RUNFILE not found: {$runfile}");
            $compileArgv[] = $runPath;
        } elseif (isset($sections['INCLUDE'])) {
            $includeFile = tempnam(sys_get_temp_dir(), 'phpc_inc_');
            $this->assertNotFalse($includeFile);
            file_put_contents($includeFile, $sections['INCLUDE']);
            $compileArgv[] = '--include';
            $compileArgv[] = $includeFile;
            $entryFile = tempnam(sys_get_temp_dir(), 'phpc_ent_').'.php';
            $this->assertNotFalse($entryFile);
            file_put_contents($entryFile, $code);
            $compileArgv[] = $entryFile;
        } else {
            $compileArgv[] = '-';
        }

        $compile = proc_open(
            array_merge(self::llvmEnvPrefix(), $this->phpCommand(), $compileArgv),
            $descriptorSpec,
            $pipes,
            $repoRoot,
            $compileEnv
        );
        if ('' === $runfile && !isset($sections['INCLUDE'])) {
            fwrite($pipes[0], $code);
        }
        fclose($pipes[0]);
        $compileErr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $compileExit = proc_close($compile);
        $compileErrText = trim($compileErr !== false ? $compileErr : '');
        $this->assertSame(
            0,
            $compileExit,
            "AOT compile failed for {$name}: {$compileErrText}"
        );
        $this->assertFileExists($outfile, $compileErrText);
        $this->assertTrue(is_executable($outfile), $compileErrText);

        $run = proc_open(
            [$outfile],
            $descriptorSpec,
            $runPipes,
            $repoRoot,
            $runEnv
        );
        $result = stream_get_contents($runPipes[1]);
        $runErr = stream_get_contents($runPipes[2]);
        fclose($runPipes[0]);
        fclose($runPipes[1]);
        fclose($runPipes[2]);
        $exitCode = proc_close($run);
        @unlink($outfile);
        if (isset($bodyFile)) {
            @unlink($bodyFile);
        }
        if (null !== $includeFile) {
            @unlink($includeFile);
        }
        if (null !== $entryFile) {
            @unlink($entryFile);
        }

        $runErrText = trim($runErr !== false ? $runErr : '');
        if (isset($sections['EXPECT_EXIT'])) {
            $this->assertSame(
                (int) trim($sections['EXPECT_EXIT']),
                $exitCode,
                "AOT run for {$name} stderr: {$runErrText}"
            );
        } else {
            $this->assertSame(0, $exitCode, "AOT run for {$name} stderr: {$runErrText}");
        }
        $this->assertExpect($result !== false ? $result : '', $sections);
    }

    /**
     * Standalone AOT binaries defer-flush header() before body output (#634).
     * Default GET unless a case explicitly tests CLI header_list() semantics.
     *
     * @param array<string, string> $env
     */
    private static function applyDefaultAotWebEnv(array &$env, string $caseName): void
    {
        if (isset($env['REQUEST_METHOD'])) {
            return;
        }
        if (str_contains($caseName, 'headers_list')) {
            return;
        }
        // Leave unset when a body is present so __phpc_sg_request_method_for infers POST (#878).
        if (isset($env['REQUEST_BODY']) && '' !== $env['REQUEST_BODY']) {
            return;
        }
        if (isset($env['REQUEST_BODY_FILE']) && '' !== $env['REQUEST_BODY_FILE']) {
            return;
        }
        $env['REQUEST_METHOD'] = 'GET';
    }

}
