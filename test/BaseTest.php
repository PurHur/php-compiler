<?php

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/PhptWebSections.php';

abstract class BaseTest extends TestCase {

    protected string $BIN = '';
    protected static string $DIR = __DIR__;

    const EXPECTATIONS = [
        'EXPECT',
        'EXPECTF',
        'EXPECTREGEX',
    ];

    const EXTERNAL_SECTIONS = [
        'FILE',
        'POST',
        'EXPECT',
        'EXPECTF',
        'EXPECTREGEX',
    ];

    const REQUIRED_SECTIONS = [
        ['FILE', 'RUNFILE'],
        ['EXPECT', 'EXPECTF', 'EXPECTREGEX', 'EXPECT_EXIT'],
    ];

    const UNSUPPORTED_SECTIONS = [
        'REDIRECTTEST',
        'REQUEST',
        'PUT',
        'POST_RAW',
        'GZIP_POST',
        'DEFLATE_POST',
        'HEADERS',
        'CGI',
        'EXPECTHEADERS',
        'EXTENSIONS',
        'PHPDBG',
    ];

    public static function providePHPTests(): \Generator {
        yield from self::providePHPTestsFromDir(static::$DIR . '/cases');
    }

    protected static function providePHPTestsFromDir(string $dir): \Generator {
        foreach (new \DirectoryIterator($dir) as $path) {
            if (!$path->isDir() || $path->isDot()) {
                continue;
            }
            yield from self::providePHPTestsFromDir($path->getPathname());
        }
        foreach (new \GlobIterator($dir . '/*.phpt') as $test) {
            $casesRoot = static::$DIR . '/cases/';
            $pathname = $test->getPathname();
            if (str_starts_with($pathname, $casesRoot)) {
                $name = preg_replace('/\.phpt$/', '', substr($pathname, strlen($casesRoot))) ?: $test->getBasename();
            } else {
                $name = preg_replace('/\.phpt$/', '', $test->getBasename()) ?: $test->getBasename();
            }
            yield $name => self::parsePHPT($pathname, $test->getBasename());
        }
    }

    protected static function parsePHPT(string $filename, string $basename): array {
        $sections = [];
        $section = '';
        foreach (file($filename) as $line) {
            if (preg_match('(^--([_A-Z]+)--)', $line, $result)) {
                $section = $result[1];
                $sections[$section] = '';
                continue;
            }
            if (empty($section)) {
                throw new \LogicException("Invalid PHPT file: empty section header");
            }
            $sections[$section] .= $line;
        }
        if (!isset($sections['TEST'])) {
            throw new \LogicException("Every test must have a name");
        }
        if (isset($sections['FILEEOF'])) {
            $sections['FILE'] = rtrim($sections['FILEEOF'], "\r\n");
            unset($sections['FILEEOF']);
        }
        self::parseExternal($sections, dirname($filename));
        if (!self::validate($sections)) {
            throw new \LogicException("Invalid PHPT File");
        }
        foreach (self::UNSUPPORTED_SECTIONS as $section) {
            if (isset($sections[$section])) {
                throw new \LogicException("PHPT $section sections are not supported");
            }
        }
        $sections['__phpt_dir'] = dirname($filename);
        $fileSection = $sections['FILE'] ?? '';

        return [
            trim($sections["TEST"]),
            $fileSection,
            $sections,
        ];
    }

    private static function parseExternal(array &$sections, string $testdir): void {
        foreach (self::EXTERNAL_SECTIONS as $section) {
            if (isset($sections[$section . '_EXTERNAL'])) {
                $filename = trim($sections[$section . '_EXTERNAL']);
                if (!is_file($testdir . '/' . $filename)) {
                    throw new \RuntimeException("Could not load external file $filename");
                }
                $sections[$section] = file_get_contents($testdir . '/' . $filename);
            }
        }
    }

    private static function validate(array &$sections): bool {
        foreach (self::REQUIRED_SECTIONS as $section) {
            if (is_array($section)) {
                foreach ($section as $any) {
                    if (isset($sections[$any])) {
                        continue 2;
                    }
                }
                return false;
            } elseif (!isset($sections[$section])) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return list<string>
     */
    protected function phpCommand(): array {
        $phpEnv = getenv('PHP_COMPILER_PHP');
        if (false !== $phpEnv && '' !== $phpEnv) {
            $cmd = preg_split('/\s+/', $phpEnv);
        } else {
            $cmd = [PHP_BINARY];
        }
        $extDir = getenv('PHP_COMPILER_EXT_DIR') ?: '/usr/lib/php/20220829';
        // MCJIT child must not preload duplicate extensions (segfault with libLLVM, #98, #2219).
        $skipExtensionPreload = '' !== $this->BIN && str_contains($this->BIN, 'jit.php');
        if (is_dir($extDir) && !$skipExtensionPreload) {
            foreach (['tokenizer', 'mbstring', 'dom', 'xml', 'xmlwriter', 'ffi', 'posix', 'phar'] as $ext) {
                if (extension_loaded($ext)) {
                    continue;
                }
                $so = $extDir.'/'.$ext.'.so';
                if (is_file($so)) {
                    $cmd[] = '-d';
                    $cmd[] = 'extension='.$so;
                }
            }
        }
        $cmd[] = '-d';
        $cmd[] = 'display_errors=0';
        $cmd[] = '-d';
        $cmd[] = 'error_reporting=0';
        $memoryLimit = getenv('PHP_COMPILER_MEMORY_LIMIT') ?: '1536M';
        if ('-1' !== $memoryLimit) {
            $cmd[] = '-d';
            $cmd[] = 'memory_limit='.$memoryLimit;
        }

        return $cmd;
    }

    /**
     * @dataProvider providePHPTests
     */
    public function testCases(string $name, string $code, array $sections): void {
        $descriptorSepc = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $repoRoot = \dirname(__DIR__);
        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        self::applyLlvmToolchainEnv($env);
        self::applyEnvSection($env, $sections);
        PhptWebSections::applyToEnv($env, $sections);
        // JIT/VM children must run llvm-env preload; PHPUnit parent skips it (#98, #2055).
        unset($env['PHP_COMPILER_SKIP_LLVM_PRELOAD']);
        // php-src run-tests.php: honor --SKIPIF-- before executing FILE (#24888).
        $skipReason = self::evaluatePhptSkipIf($sections, $env, $this->phpCommand(), $repoRoot);
        if (null !== $skipReason) {
            $this->markTestSkipped($skipReason);
        }
        $runfile = isset($sections['RUNFILE']) ? trim($sections['RUNFILE']) : '';
        if ('' !== $runfile) {
            $runPath = realpath(($sections['__phpt_dir'] ?? $repoRoot) . '/' . $runfile);
            if (false === $runPath) {
                $this->fail("RUNFILE not found: {$runfile}");
            }
            $vmCmd = array_merge($this->phpCommand(), [$this->BIN, $runPath]);
            $cwd = dirname($runPath);
            $stdin = null;
        } else {
            $vmCmd = array_merge($this->phpCommand(), [$this->BIN]);
            $vmCmd = array_merge($vmCmd, self::phptIniArgvFlags($sections));
            if (str_contains((string) $this->BIN, 'jit.php')) {
                $vmCmd = array_merge($vmCmd, PhptWebSections::compileArgvFlags($sections));
            }
            $cwd = $repoRoot;
            $stdin = $code;
        }
        $cmd = array_merge(self::llvmEnvPrefix(), $vmCmd);
        [$result, $exitCode, $stderr] = self::runVmSubprocess($cmd, $cwd, $env, $stdin, $name);
        if (isset($sections['EXPECT_EXIT'])) {
            $this->assertSame((int) trim($sections['EXPECT_EXIT']), $exitCode, "VM exit for {$name}");
        } elseif (0 !== $exitCode) {
            $detail = trim($stderr);
            if ('' === $detail) {
                $detail = '(no stderr)';
            }
            $this->fail("VM exited with code {$exitCode} for {$name}: {$detail}");
        }
        if (isset($sections['EXPECT']) || isset($sections['EXPECTF']) || isset($sections['EXPECTREGEX'])) {
            $stderrTrim = trim($stderr);
            $stdoutTrim = trim($result);
            if (isset($sections['EXPECT_EXIT']) && '' !== $stdoutTrim && str_contains($stderrTrim, 'PHP Fatal error:')) {
                // PHPT --EXPECT-- is stdout; uncaught fatals after partial output land on stderr (#7468).
                $merged = $stdoutTrim;
            } elseif ('' === $stdoutTrim && '' !== $stderrTrim) {
                // Stderr-only scripts (no user echo output).
                $merged = $stderrTrim;
            } elseif (
                '' !== $stderrTrim
                && '' !== $stdoutTrim
                && self::phptExpectReferencesCliDiagnostics($sections)
                && !self::stdoutAlreadyContainsCliDiagnostics($stdoutTrim)
            ) {
                // PHPT fixtures that list PHP Warning/Notice/Deprecated in EXPECT need stderr merged
                // when display_errors=0 on the driver (#17398). Skip merge when VM already echoed
                // diagnostics to stdout (display_errors=1 inside the script).
                $merged = $stderrTrim."\n".$stdoutTrim;
            } else {
                // php-src PHPT compares stdout; CLI notices/warnings stay on stderr (#13486, #16702).
                $merged = $stdoutTrim;
            }
            $this->assertExpect($merged, $sections);
        }
    }

    const ASSERTIONS = [
        'EXPECT' => 'assertEquals',
        'EXPECTF' => 'assertStringMatchesFormat',
        'EXPECTREGEX' => 'assertRegExp',
    ];

    protected function assertExpect(string $result, array $sections): void {
        $actual = $this->normalize($result);
        foreach (self::ASSERTIONS as $action => $selectedAssertion) {
            if (isset($sections[$action])) {
                $content = preg_replace('(\r\n?)', "\n", trim($sections[$action]));
                $content = $this->normalize($content);
                $expected = $action === "EXPECTREGEX" ? "/{$content}/" : $content;
                if ($expected === null) {
                    throw new \LogicException("No PHPT expectation found");
                }
                if ('EXPECTREGEX' === $action && method_exists($this, 'assertMatchesRegularExpression')) {
                    $this->assertMatchesRegularExpression($expected, $actual);
                } else {
                    $this->$selectedAssertion($expected, $actual);
                }
                return;
            }
        }
        throw new \RuntimeException('No PHPT assertion found');
    }

    /**
     * True when a PHPT EXPECT section includes CLI diagnostics that land on stderr with display_errors=0.
     *
     * Matches both Zend CLI `PHP Warning:` and compile-time magic visibility lines that
     * fwrite bare `Warning:` (MagicMethodStaticCheck, #26439 / #26438).
     *
     * @param array<string, string> $sections
     */
    protected static function phptExpectReferencesCliDiagnostics(array $sections): bool
    {
        foreach (['EXPECT', 'EXPECTF', 'EXPECTREGEX'] as $key) {
            if (!isset($sections[$key])) {
                continue;
            }
            if (preg_match('/(?:^|\n)(?:PHP )?(?:Warning|Notice|Deprecated):/', $sections[$key])) {
                return true;
            }
        }

        return false;
    }

    protected static function stdoutAlreadyContainsCliDiagnostics(string $stdout): bool
    {
        return (bool) preg_match('/(?:^|\n)(?:PHP )?(?:Warning|Notice|Deprecated):/', $stdout);
    }

    protected function normalize(string $string): string {
        $result = preg_replace('(\r\n)', "\n", trim($string)); // get rid of \r\n
        $result = preg_replace('(^\s+)m', '', $result); // get rid of leading whitespace
        $result = preg_replace('(\s+$)m', '', $result); // get rid of trailing whitespace
        $result = preg_replace('(\n\n+)', "\n", $result); // get rid of blank lines
        return $result;
    }

    /**
     * @param array<string, string> $sections
     *
     * @return list<string>
     */
    protected static function phptIniArgvFlags(array $sections): array
    {
        if (!isset($sections['INI'])) {
            return [];
        }
        $phptDir = $sections['__phpt_dir'] ?? \dirname(__DIR__);
        $argv = [];
        foreach (explode("\n", trim($sections['INI'])) as $line) {
            $line = trim($line);
            if ('' === $line || str_starts_with($line, ';')) {
                continue;
            }
            $line = str_replace('{PWD}', $phptDir, $line);
            $argv[] = '-d';
            $argv[] = $line;
        }

        return $argv;
    }

    /**
     * @param array<string, string> $env
     * @param array<string, string> $sections
     */
    protected static function applyEnvSection(array &$env, array $sections): void
    {
        if (!isset($sections['ENV'])) {
            return;
        }
        foreach (explode("\n", trim($sections['ENV'])) as $line) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }
            $parts = explode('=', $line, 2);
            if (2 !== count($parts)) {
                throw new \LogicException("Invalid ENV line: {$line}");
            }
            $env[$parts[0]] = $parts[1];
        }
    }

    /**
     * Evaluate a PHPT --SKIPIF-- section under host PHP (php-src run-tests.php semantics).
     *
     * Returns the skip message when output begins with "skip" (case-insensitive), or null when
     * the case should run. Prepends vendor/autoload.php so SKIPIF can call CompilerVersion /
     * extension policies. Cwd is the PHPT directory.
     *
     * @param array<string, string> $sections
     * @param array<string, string> $env
     * @param list<string>          $phpCommand
     */
    public static function evaluatePhptSkipIf(
        array $sections,
        array $env,
        array $phpCommand,
        string $repoRoot
    ): ?string {
        if (!isset($sections['SKIPIF'])) {
            return null;
        }
        $skipif = $sections['SKIPIF'];
        if ('' === trim($skipif)) {
            return null;
        }
        $cwd = $sections['__phpt_dir'] ?? $repoRoot;
        $autoload = $repoRoot . '/vendor/autoload.php';
        $tmp = tempnam(sys_get_temp_dir(), 'phpt-skipif-');
        if (false === $tmp) {
            throw new \RuntimeException('Failed to allocate temp file for SKIPIF');
        }
        $skipifPath = $tmp . '.php';
        rename($tmp, $skipifPath);
        try {
            // auto_prepend_file loads Composer so SKIPIF may reference PHPCompiler\* (#24888).
            $body = $skipif;
            if (!preg_match('/^\s*<\?php/i', $body)) {
                $body = "<?php\n" . $body;
            }
            if (!file_put_contents($skipifPath, $body)) {
                throw new \RuntimeException("Failed to write SKIPIF temp file: {$skipifPath}");
            }
            $cmd = $phpCommand;
            if (is_file($autoload)) {
                $cmd[] = '-d';
                $cmd[] = 'auto_prepend_file=' . $autoload;
            }
            foreach (self::phptIniArgvFlags($sections) as $flag) {
                $cmd[] = $flag;
            }
            $cmd[] = $skipifPath;
            $descriptorSpec = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $pipes = [];
            $proc = proc_open($cmd, $descriptorSpec, $pipes, $cwd, $env);
            if (!\is_resource($proc)) {
                throw new \RuntimeException('Failed to spawn SKIPIF subprocess');
            }
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($proc);
            $out = is_string($stdout) ? $stdout : '';
            // php-src: SKIPIF may print to stdout; treat stderr-only when stdout empty.
            if ('' === trim($out) && is_string($stderr) && '' !== trim($stderr)) {
                $out = $stderr;
            }
            $trimmed = ltrim($out);
            if (0 === strncasecmp($trimmed, 'skip', 4)) {
                return trim($out);
            }
            if (0 !== $exitCode) {
                $detail = trim($out);
                if ('' === $detail && is_string($stderr)) {
                    $detail = trim($stderr);
                }
                if ('' === $detail) {
                    $detail = "(exit {$exitCode})";
                }
                throw new \RuntimeException("SKIPIF bork for PHPT: {$detail}");
            }
            // Non-empty output that is not a skip message → bork (php-src run-tests.php).
            if ('' !== trim($out)) {
                throw new \RuntimeException('SKIPIF bork for PHPT: ' . trim($out));
            }

            return null;
        } finally {
            @unlink($skipifPath);
        }
    }

    /**
     * @param array<string, string> $env
     */
    protected static function applyLlvmToolchainEnv(array &$env): void
    {
        LlvmToolchain::applyProcessEnv($env, dirname(__DIR__));
    }

    /**
     * @return list<string>
     */
    protected static function llvmEnvPrefix(): array
    {
        return LlvmToolchain::envPrefix(dirname(__DIR__));
    }

    /**
     * @param list<string>  $cmd
     * @param array<string, string> $env
     */
    /**
     * @return array{0: string, 1: int, 2: string}
     */
    protected static function runVmSubprocess(array $cmd, string $cwd, array $env, ?string $stdin, string $testName): array
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $cwd, $env);
        if (!\is_resource($proc)) {
            throw new \RuntimeException("Failed to spawn VM for test: {$testName}");
        }
        if (null !== $stdin) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);

        $guard = '1' === getenv('PHP_COMPILER_VM_RSS_GUARD');
        $maxMb = (int) (getenv('PHP_COMPILER_VM_PEAK_RSS_MB') ?: '2048');
        $maxKb = $maxMb * 1024;
        $peakKb = 0;
        $lastExitCode = 0;
        /** @var array<int, bool> */
        $sigcontSent = [];

        while (true) {
            $status = proc_get_status($proc);
            if ($guard && isset($status['pid'])) {
                $peakKb = max($peakKb, self::peakRssKbForTree((int) $status['pid']));
                if ($peakKb > $maxKb) {
                    proc_terminate($proc, 9);
                    proc_close($proc);
                    fclose($pipes[1]);
                    fclose($pipes[2]);
                    throw new \RuntimeException(sprintf(
                        'VM RSS guard: test "%s" exceeded %d MiB (peak %d MiB)',
                        $testName,
                        $maxMb,
                        (int) ceil($peakKb / 1024)
                    ));
                }
            }
            if (isset($status['pid'])) {
                self::ensureNoStoppedChildren((int) $status['pid'], $testName, $sigcontSent);
            }
            if (!$status['running']) {
                $lastExitCode = (int) ($status['exitcode'] ?? 0);
                break;
            }
            usleep(150000);
        }

        $result = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        $exitCode = $lastExitCode;

        if ($guard && $peakKb > 0) {
            fwrite(STDERR, sprintf(
                "vm-rss-guard: test=%s peak_rss_mb=%d\n",
                $testName,
                (int) ceil($peakKb / 1024)
            ));
        }

        return [
            $result !== false ? $result : '',
            (int) $exitCode,
            $stderr !== false ? $stderr : '',
        ];
    }

    private static function peakRssKbForTree(int $rootPid): int
    {
        $peak = 0;
        foreach (self::processTreePids($rootPid) as $pid) {
            $statusFile = "/proc/{$pid}/status";
            if (!is_readable($statusFile)) {
                continue;
            }
            $lines = file($statusFile, FILE_IGNORE_NEW_LINES);
            if (false === $lines) {
                continue;
            }
            foreach ($lines as $line) {
                if (0 === strpos($line, 'VmRSS:')) {
                    $parts = preg_split('/\s+/', trim($line));
                    if (isset($parts[1])) {
                        $peak = max($peak, (int) $parts[1]);
                    }
                    break;
                }
            }
        }

        return $peak;
    }

    /**
     * @return list<int>
     */
    private static function processTreePids(int $rootPid): array
    {
        $pids = [$rootPid];
        $offset = 0;
        while ($offset < count($pids)) {
            $parent = $pids[$offset];
            $offset++;
            $children = @file("/proc/{$parent}/task/{$parent}/children", FILE_IGNORE_NEW_LINES);
            if (false === $children || !isset($children[0]) || '' === $children[0]) {
                continue;
            }
            foreach (preg_split('/\s+/', trim($children[0])) as $child) {
                if ('' !== $child) {
                    $pids[] = (int) $child;
                }
            }
        }

        return $pids;
    }

    /**
     * PHPUnit occasionally hangs when a vm child is stopped (issue #16657).
     * If we detect a stopped process in the VM pid tree, send SIGCONT and emit
     * a single diagnostic line (per pid per test) so the battery can complete.
     *
     * @param array<int, bool> $sigcontSent
     */
    private static function ensureNoStoppedChildren(int $rootPid, string $testName, array &$sigcontSent): void
    {
        foreach (self::processTreePids($rootPid) as $pid) {
            if (isset($sigcontSent[$pid])) {
                continue;
            }
            if (!self::isProcessStopped($pid)) {
                continue;
            }
            self::sendSigcont($pid);
            $sigcontSent[$pid] = true;
            fwrite(STDERR, sprintf("vm-sigcont: test=%s pid=%d\n", $testName, $pid));
        }
    }

    private static function isProcessStopped(int $pid): bool
    {
        $statFile = "/proc/{$pid}/stat";
        $stat = @file_get_contents($statFile);
        if (false === $stat || '' === $stat) {
            return false;
        }
        // /proc/<pid>/stat: "pid (comm) state ..."
        $close = strrpos($stat, ')');
        if (false === $close) {
            return false;
        }
        $rest = substr($stat, $close + 2);
        if (false === $rest || '' === $rest) {
            return false;
        }
        $state = $rest[0] ?? '';
        return $state === 'T' || $state === 't';
    }

    private static function sendSigcont(int $pid): void
    {
        if (function_exists('posix_kill')) {
            @posix_kill($pid, SIGCONT);
            return;
        }
        // Best-effort fallback (posix may be unavailable in some envs).
        @exec('kill -CONT ' . (int) $pid . ' 2>/dev/null');
    }

}
