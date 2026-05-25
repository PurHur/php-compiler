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
        self::EXPECTATIONS,
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
        if (is_dir($extDir)) {
            foreach (['tokenizer', 'mbstring', 'dom', 'xml', 'xmlwriter', 'ffi', 'posix', 'phar'] as $ext) {
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
            if (str_contains((string) $this->BIN, 'jit.php')) {
                $vmCmd = array_merge($vmCmd, PhptWebSections::compileArgvFlags($sections));
            }
            $cwd = $repoRoot;
            $stdin = $code;
        }
        $cmd = array_merge(self::llvmEnvPrefix(), $vmCmd);
        $result = self::runVmSubprocess($cmd, $cwd, $env, $stdin, $name);
        $this->assertExpect($result, $sections);
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

    protected function normalize(string $string): string {
        $result = preg_replace('(\r\n)', "\n", trim($string)); // get rid of \r\n
        $result = preg_replace('(^\s+)m', '', $result); // get rid of leading whitespace
        $result = preg_replace('(\s+$)m', '', $result); // get rid of trailing whitespace
        $result = preg_replace('(\n\n+)', "\n", $result); // get rid of blank lines
        return $result;
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
    protected static function runVmSubprocess(array $cmd, string $cwd, array $env, ?string $stdin, string $testName): string
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
            if (!$status['running']) {
                break;
            }
            usleep(150000);
        }

        $result = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        if ($guard && $peakKb > 0) {
            fwrite(STDERR, sprintf(
                "vm-rss-guard: test=%s peak_rss_mb=%d\n",
                $testName,
                (int) ceil($peakKb / 1024)
            ));
        }

        return $result;
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

}