<?php

declare(strict_types=1);

/**
 * Run php-src-shaped .phpt cases under Zend / VM / AOT and diff failing-name sets (#36381).
 *
 * Usage (via script/php-src/php-src-phpt.sh):
 *   --php-src=<checkout> --dirs=Zend/tests,ext/standard/tests --backend=vm
 *   --corpus=sample --backend=vm --collect   # harness self-test under test/php-src/corpus/
 *   --diff --backend=vm --corpus=sample
 *
 * Compare by failing case NAME sets (AGENTS.md §2) — never by counts.
 */

$repoRoot = dirname(__DIR__, 2);
require_once $repoRoot . '/vendor/autoload.php';
require_once $repoRoot . '/test/BaseTest.php';

use PHPCompiler\BaseTest;

final class PhpSrcPhptRunner
{
    /** @var list<string> */
    private array $phpCommand;

    public function __construct(
        private readonly string $repoRoot,
        private readonly string $backend,
        private readonly int $timeoutSec,
    ) {
        $this->phpCommand = $this->buildPhpCommand();
    }

    /**
     * @return list<string>
     */
    private function buildPhpCommand(): array
    {
        $phpEnv = getenv('PHP_COMPILER_PHP');
        if (false !== $phpEnv && '' !== $phpEnv) {
            $parts = preg_split('/\s+/', $phpEnv) ?: [];
        } else {
            $parts = [PHP_BINARY];
        }
        $cmd = [];
        foreach ($parts as $p) {
            if ('' !== $p) {
                $cmd[] = $p;
            }
        }
        $cmd[] = '-d';
        $cmd[] = 'display_errors=stderr';
        $cmd[] = '-d';
        $cmd[] = 'error_reporting=E_ALL';
        $memory = getenv('PHP_COMPILER_MEMORY_LIMIT') ?: '512M';
        $cmd[] = '-d';
        $cmd[] = 'memory_limit=' . $memory;

        return $cmd;
    }

    /**
     * @return list<string> relative names (no .phpt)
     */
    public static function listCases(string $casesRoot): array
    {
        if (!is_dir($casesRoot)) {
            throw new RuntimeException("php-src-phpt: cases root missing: {$casesRoot}");
        }
        $names = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($casesRoot, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            /** @var SplFileInfo $file */
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.phpt')) {
                continue;
            }
            $full = $file->getPathname();
            $rel = substr($full, strlen(rtrim($casesRoot, '/')) + 1);
            $names[] = preg_replace('/\.phpt$/', '', $rel) ?: $rel;
        }
        $names = array_values(array_unique($names));
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * Hash-stable shard membership (#24498).
     *
     * @param list<string> $names
     * @return list<string>
     */
    public static function shardCases(array $names, int $shards, int $shard): array
    {
        if ($shards < 1 || $shard < 0 || $shard >= $shards) {
            throw new InvalidArgumentException("php-src-phpt: bad shard {$shard}/{$shards}");
        }
        $out = [];
        foreach ($names as $name) {
            if (((crc32($name) & 0xffffffff) % $shards) === $shard) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /**
     * @return array{status:string,detail:?string}
     *   status = pass|fail|skip|bork
     */
    public function runOne(string $casesRoot, string $name): array
    {
        $path = $casesRoot . '/' . $name . '.phpt';
        if (!is_file($path)) {
            return ['status' => 'bork', 'detail' => "missing {$path}"];
        }

        try {
            $parsed = $this->parsePhptPublic($path);
        } catch (Throwable $e) {
            return ['status' => 'bork', 'detail' => $e->getMessage()];
        }
        [$testName, $code, $sections] = $parsed;

        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $env[$key] = $value;
            }
        }
        unset($env['PHP_COMPILER_SKIP_LLVM_PRELOAD']);

        try {
            $skip = BaseTest::evaluatePhptSkipIf($sections, $env, $this->phpCommand, $this->repoRoot);
        } catch (Throwable $e) {
            return ['status' => 'bork', 'detail' => 'SKIPIF: ' . $e->getMessage()];
        }
        if (null !== $skip) {
            return ['status' => 'skip', 'detail' => $skip];
        }

        $bin = match ($this->backend) {
            'zend' => null,
            'vm' => $this->repoRoot . '/bin/vm.php',
            'aot' => 'aot',
            default => throw new InvalidArgumentException("unknown backend {$this->backend}"),
        };

        if ('aot' === $this->backend) {
            return $this->runAot($name, $code, $sections, $env);
        }

        $cmd = $this->phpCommand;
        if (null !== $bin) {
            $cmd[] = $bin;
        }
        foreach ($this->phptIniFlags($sections) as $flag) {
            $cmd[] = $flag;
        }

        $cwd = $this->repoRoot;
        $stdin = $code;
        if (isset($sections['RUNFILE'])) {
            $runfile = trim($sections['RUNFILE']);
            $runPath = realpath(($sections['__phpt_dir'] ?? $cwd) . '/' . $runfile);
            if (false === $runPath) {
                return ['status' => 'bork', 'detail' => "RUNFILE not found: {$runfile}"];
            }
            $cmd[] = $runPath;
            $cwd = dirname($runPath);
            $stdin = null;
        }

        [$stdout, $stderr, $exitCode] = $this->spawn($cmd, $cwd, $env, $stdin);
        if (isset($sections['EXPECT_EXIT'])) {
            $want = (int) trim($sections['EXPECT_EXIT']);
            if ($exitCode !== $want) {
                return [
                    'status' => 'fail',
                    'detail' => "exit {$exitCode} want {$want}; stderr=" . trim($stderr),
                ];
            }
        } elseif (0 !== $exitCode) {
            return [
                'status' => 'fail',
                'detail' => "exit {$exitCode}; stderr=" . trim($stderr),
            ];
        }

        if (!isset($sections['EXPECT']) && !isset($sections['EXPECTF']) && !isset($sections['EXPECTREGEX'])) {
            return ['status' => 'pass', 'detail' => null];
        }

        $merged = $this->mergeOutput($stdout, $stderr, $sections);
        $ok = $this->assertExpect($merged, $sections);
        if (!$ok) {
            return [
                'status' => 'fail',
                'detail' => 'EXPECT mismatch; got=' . $this->normalize($merged),
            ];
        }

        return ['status' => 'pass', 'detail' => $testName];
    }

    /**
     * @param array<string, string> $sections
     * @param array<string, string> $env
     * @return array{status:string,detail:?string}
     */
    private function runAot(string $name, string $code, array $sections, array $env): array
    {
        $work = $this->repoRoot . '/build/php-src-phpt/aot/' . preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
        if (!is_dir($work) && !mkdir($work, 0755, true) && !is_dir($work)) {
            return ['status' => 'bork', 'detail' => "mkdir failed: {$work}"];
        }
        $src = $work . '/case.php';
        $bin = $work . '/case.bin';
        if (false === file_put_contents($src, $code)) {
            return ['status' => 'bork', 'detail' => "write failed: {$src}"];
        }
        $compileCmd = array_merge($this->phpCommand, [
            $this->repoRoot . '/bin/compile.php',
            '-o',
            $bin,
            $src,
        ]);
        [$cOut, $cErr, $cRc] = $this->spawn($compileCmd, $this->repoRoot, $env, null);
        if (0 !== $cRc || !is_file($bin)) {
            return [
                'status' => 'fail',
                'detail' => 'aot compile rc=' . $cRc . ' ' . trim($cOut . "\n" . $cErr),
            ];
        }
        [$stdout, $stderr, $exitCode] = $this->spawn([$bin], $this->repoRoot, $env, null);
        if (isset($sections['EXPECT_EXIT'])) {
            $want = (int) trim($sections['EXPECT_EXIT']);
            if ($exitCode !== $want) {
                return ['status' => 'fail', 'detail' => "aot exit {$exitCode} want {$want}"];
            }
        } elseif (0 !== $exitCode) {
            return ['status' => 'fail', 'detail' => "aot exit {$exitCode}; " . trim($stderr)];
        }
        if (!isset($sections['EXPECT']) && !isset($sections['EXPECTF']) && !isset($sections['EXPECTREGEX'])) {
            return ['status' => 'pass', 'detail' => null];
        }
        $merged = $this->mergeOutput($stdout, $stderr, $sections);
        if (!$this->assertExpect($merged, $sections)) {
            return ['status' => 'fail', 'detail' => 'aot EXPECT mismatch; got=' . $this->normalize($merged)];
        }

        return ['status' => 'pass', 'detail' => null];
    }

    /**
     * @return array{0:string,1:string,array<string,string>}
     */
    private function parsePhptPublic(string $path): array
    {
        $ref = new ReflectionClass(BaseTest::class);
        $m = $ref->getMethod('parsePHPT');
        $m->setAccessible(true);
        /** @var array{0:string,1:string,2:array<string,string>} $parsed */
        $parsed = $m->invoke(null, $path, basename($path));

        return $parsed;
    }

    /**
     * @param array<string, string> $sections
     * @return list<string>
     */
    private function phptIniFlags(array $sections): array
    {
        $ref = new ReflectionClass(BaseTest::class);
        $m = $ref->getMethod('phptIniArgvFlags');
        $m->setAccessible(true);
        /** @var list<string> $flags */
        $flags = $m->invoke(null, $sections);

        return $flags;
    }

    /**
     * @param list<string>          $cmd
     * @param array<string, string> $env
     * @return array{0:string,1:string,2:int}
     */
    private function spawn(array $cmd, string $cwd, array $env, ?string $stdin): array
    {
        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $proc = proc_open($cmd, $descriptor, $pipes, $cwd, $env);
        if (!is_resource($proc)) {
            throw new RuntimeException('php-src-phpt: failed to spawn: ' . implode(' ', $cmd));
        }
        if (null !== $stdin) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);

        $stdout = '';
        $stderr = '';
        $start = microtime(true);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        while (true) {
            $status = proc_get_status($proc);
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            if (!$status['running']) {
                $exit = (int) $status['exitcode'];
                // Drain once more after exit.
                $stdout .= (string) stream_get_contents($pipes[1]);
                $stderr .= (string) stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);

                return [$stdout, $stderr, $exit];
            }
            if ((microtime(true) - $start) > $this->timeoutSec) {
                proc_terminate($proc, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);

                return [$stdout, $stderr . "\n[php-src-phpt timeout {$this->timeoutSec}s]", 124];
            }
            usleep(20000);
        }
    }

    /**
     * @param array<string, string> $sections
     */
    private function mergeOutput(string $stdout, string $stderr, array $sections): string
    {
        $stdoutTrim = trim($stdout);
        $stderrTrim = trim($stderr);
        if ('' === $stdoutTrim && '' !== $stderrTrim) {
            return $stderrTrim;
        }

        return $stdoutTrim;
    }

    /**
     * @param array<string, string> $sections
     */
    private function assertExpect(string $result, array $sections): bool
    {
        $actual = $this->normalize($result);
        if (isset($sections['EXPECT'])) {
            return $actual === $this->normalize($sections['EXPECT']);
        }
        if (isset($sections['EXPECTREGEX'])) {
            $pattern = '/' . trim($sections['EXPECTREGEX']) . '/s';

            return 1 === preg_match($pattern, $actual);
        }
        if (isset($sections['EXPECTF'])) {
            return $this->matchExpectF($this->normalize($sections['EXPECTF']), $actual);
        }

        return false;
    }

    private function normalize(string $string): string
    {
        $result = preg_replace('(\r\n)', "\n", trim($string)) ?? '';
        $result = preg_replace('(^\s+)m', '', $result) ?? $result;
        $result = preg_replace('(\s+$)m', '', $result) ?? $result;
        $result = preg_replace('(\n\n+)', "\n", $result) ?? $result;

        return $result;
    }

    /**
     * Minimal php-src EXPECTF matcher (%s %d %i %f %c %x %e %% and literal text).
     */
    private function matchExpectF(string $want, string $got): bool
    {
        $re = '';
        $len = strlen($want);
        for ($i = 0; $i < $len; $i++) {
            $ch = $want[$i];
            if ('%' !== $ch) {
                $re .= preg_quote($ch, '/');
                continue;
            }
            if ($i + 1 >= $len) {
                $re .= '%';
                break;
            }
            $code = $want[$i + 1];
            $i++;
            $re .= match ($code) {
                '%' => '%',
                's' => '.+?',
                'a' => '.+?',
                'w' => '\s*',
                'd' => '\d+',
                'i' => '[+-]?\d+',
                'f' => '[+-]?\.?\d+\.?\d*(?:[Ee][+-]?\d+)?',
                'c' => '.',
                'x' => '[0-9A-Fa-f]+',
                'e' => '.+',
                default => preg_quote('%' . $code, '/'),
            };
        }

        return 1 === preg_match('/^' . $re . '$/s', $got);
    }
}

/**
 * @return array<string, mixed>
 */
function php_src_phpt_parse_argv(array $argv): array
{
    $opts = [
        'php_src' => null,
        'dirs' => [],
        'corpus' => null,
        'backend' => 'vm',
        'shards' => 1,
        'shard' => 0,
        'timeout' => 30,
        'mode' => 'run', // run|collect|diff|list
        'out_dir' => null,
        'baseline_dir' => null,
        'limit' => 0,
        'scoreboard' => false,
    ];
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--php-src=')) {
            $opts['php_src'] = substr($arg, 10);
        } elseif (str_starts_with($arg, '--dirs=')) {
            $dirs = substr($arg, 7);
            $opts['dirs'] = array_values(array_filter(array_map('trim', explode(',', $dirs))));
        } elseif (str_starts_with($arg, '--corpus=')) {
            $opts['corpus'] = substr($arg, 9);
        } elseif (str_starts_with($arg, '--backend=')) {
            $opts['backend'] = substr($arg, 10);
        } elseif (str_starts_with($arg, '--shards=')) {
            $opts['shards'] = (int) substr($arg, 9);
        } elseif (str_starts_with($arg, '--shard=')) {
            $opts['shard'] = (int) substr($arg, 8);
        } elseif (str_starts_with($arg, '--timeout=')) {
            $opts['timeout'] = (int) substr($arg, 10);
        } elseif (str_starts_with($arg, '--out=')) {
            $opts['out_dir'] = substr($arg, 6);
        } elseif (str_starts_with($arg, '--baseline-dir=')) {
            $opts['baseline_dir'] = substr($arg, 15);
        } elseif (str_starts_with($arg, '--limit=')) {
            $opts['limit'] = (int) substr($arg, 8);
        } elseif ('--collect' === $arg) {
            $opts['mode'] = 'collect';
        } elseif ('--diff' === $arg) {
            $opts['mode'] = 'diff';
        } elseif ('--list' === $arg) {
            $opts['mode'] = 'list';
        } elseif ('--scoreboard' === $arg) {
            $opts['scoreboard'] = true;
        } elseif ('--help' === $arg || '-h' === $arg) {
            $opts['mode'] = 'help';
        }
    }

    return $opts;
}

function php_src_phpt_usage(): void
{
    fwrite(STDERR, <<<'USAGE'
Usage: script/php-src/php-src-phpt.sh [options]

  --php-src=<checkout>     php-src tree at a php-8.2.x tag
  --dirs=Zend/tests,...    comma-separated dirs under --php-src
  --corpus=sample          use test/php-src/corpus/<name> (harness self-test)
  --backend=vm|zend|aot    default vm
  --shards=N --shard=K     hash-stable shard (crc32 % N)
  --timeout=SEC            per-case timeout (default 30)
  --collect                write failing/executed/skipped name sets under --out
  --diff                   compare current run to committed baselines
  --list                   print case names only
  --scoreboard             write docs/pages/php-src.html after a run
  --limit=N                stop after N cases (dev)

Empty result sets fail hard (artifact honesty).

USAGE);
}

/**
 * @param list<string> $lines
 */
function php_src_phpt_write_lines(string $path, array $lines): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException("mkdir failed: {$dir}");
    }
    $body = '' === implode('', $lines) ? '' : implode("\n", $lines) . "\n";
    if (false === file_put_contents($path, $body)) {
        throw new RuntimeException("write failed: {$path}");
    }
}

/**
 * @return list<string>
 */
function php_src_phpt_read_lines(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $raw = file($path, FILE_IGNORE_NEW_LINES);
    if (false === $raw) {
        return [];
    }
    $out = [];
    foreach ($raw as $line) {
        $line = trim($line);
        if ('' === $line || str_starts_with($line, '#')) {
            continue;
        }
        $out[] = $line;
    }
    sort($out, SORT_STRING);

    return array_values(array_unique($out));
}

/**
 * @param array<string, mixed> $summary
 */
function php_src_phpt_write_scoreboard(string $repoRoot, array $summary): void
{
    $path = $repoRoot . '/docs/pages/php-src.html';
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException("mkdir failed: {$dir}");
    }
    $generated = htmlspecialchars((string) ($summary['generated_at'] ?? gmdate('c')), ENT_QUOTES);
    $backend = htmlspecialchars((string) ($summary['backend'] ?? ''), ENT_QUOTES);
    $label = htmlspecialchars((string) ($summary['label'] ?? ''), ENT_QUOTES);
    $executed = (int) ($summary['executed'] ?? 0);
    $pass = (int) ($summary['pass'] ?? 0);
    $fail = (int) ($summary['fail'] ?? 0);
    $skip = (int) ($summary['skip'] ?? 0);
    $bork = (int) ($summary['bork'] ?? 0);
    $pct = $executed > 0 ? round(100.0 * $pass / $executed, 1) : 0.0;
    $rows = '';
    foreach (($summary['top_failures'] ?? []) as $name) {
        $rows .= '<li><code>' . htmlspecialchars((string) $name, ENT_QUOTES) . '</code></li>';
    }
    if ('' === $rows) {
        $rows = '<li><em>none</em></li>';
    }
    $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>php-src PHPT scoreboard (#36381)</title>
<style>
body{font-family:ui-monospace,Menlo,Consolas,monospace;margin:2rem;max-width:52rem;line-height:1.45}
h1{font-size:1.25rem} table{border-collapse:collapse} td,th{padding:.35rem .7rem;border-bottom:1px solid #ddd;text-align:left}
</style>
</head>
<body>
<h1>php-src PHPT scoreboard</h1>
<p>Generated <time>{$generated}</time> · backend=<strong>{$backend}</strong> · corpus/dirs=<strong>{$label}</strong> · issue <a href="https://github.com/PurHur/php-compiler/issues/36381">#36381</a></p>
<table>
<tr><th>executed</th><td>{$executed}</td></tr>
<tr><th>pass</th><td>{$pass} ({$pct}%)</td></tr>
<tr><th>fail</th><td>{$fail}</td></tr>
<tr><th>skip</th><td>{$skip}</td></tr>
<tr><th>bork</th><td>{$bork}</td></tr>
</table>
<h2>Top failing names</h2>
<ul>
{$rows}
</ul>
<p>Gate: <code>script/php-src/php-src-phpt.sh --corpus=sample --backend=vm --diff</code></p>
</body>
</html>
HTML;
    if (false === file_put_contents($path, $html)) {
        throw new RuntimeException("write failed: {$path}");
    }
}

// --- main (only when executed as CLI entrypoint; unit tests require this file) ---
if (!isset($argv[0]) || realpath((string) $argv[0]) !== realpath(__FILE__)) {
    return;
}

$opts = php_src_phpt_parse_argv(array_slice($argv, 1));
if ('help' === $opts['mode']) {
    php_src_phpt_usage();
    exit(0);
}

$backend = (string) $opts['backend'];
if (!in_array($backend, ['vm', 'zend', 'aot'], true)) {
    fwrite(STDERR, "php-src-phpt: bad --backend={$backend}\n");
    exit(2);
}

$casesRoot = null;
$label = '';
if (null !== $opts['corpus'] && '' !== $opts['corpus']) {
    $casesRoot = $repoRoot . '/test/php-src/corpus/' . $opts['corpus'];
    $label = 'corpus/' . $opts['corpus'];
} elseif (null !== $opts['php_src'] && [] !== $opts['dirs']) {
    // Multi-dir: for slice 1 we require a single --dirs entry or a combined scan list.
    $roots = [];
    foreach ($opts['dirs'] as $d) {
        $p = rtrim((string) $opts['php_src'], '/') . '/' . ltrim($d, '/');
        if (!is_dir($p)) {
            fwrite(STDERR, "php-src-phpt: missing dir {$p}\n");
            exit(2);
        }
        $roots[] = $p;
    }
    if (1 === count($roots)) {
        $casesRoot = $roots[0];
        $label = (string) $opts['dirs'][0];
    } else {
        // Flatten into a temp name-space by scanning each root with a dir prefix.
        $casesRoot = $roots; // handled below
        $label = implode(',', $opts['dirs']);
    }
} else {
    fwrite(STDERR, "php-src-phpt: need --corpus=NAME or --php-src=… --dirs=…\n");
    php_src_phpt_usage();
    exit(2);
}

$all = [];
if (is_array($casesRoot)) {
    foreach ($casesRoot as $idx => $root) {
        $prefix = (string) $opts['dirs'][$idx];
        foreach (PhpSrcPhptRunner::listCases($root) as $name) {
            $all[] = $prefix . '/' . $name;
        }
    }
    // For multi-dir runs, resolve path per case via php_src + name.
    $multiMap = [];
    foreach ($all as $fullName) {
        $multiMap[$fullName] = rtrim((string) $opts['php_src'], '/') . '/' . $fullName . '.phpt';
    }
} else {
    $all = PhpSrcPhptRunner::listCases($casesRoot);
    $multiMap = null;
}

if (0 === count($all)) {
    fwrite(STDERR, "php-src-phpt: found zero .phpt under {$label} — empty result is not a pass (#36381)\n");
    exit(2);
}

$selected = PhpSrcPhptRunner::shardCases($all, (int) $opts['shards'], (int) $opts['shard']);
if (0 === count($selected)) {
    fwrite(STDERR, "php-src-phpt: shard selected zero cases — empty result is not a pass\n");
    exit(2);
}
if ((int) $opts['limit'] > 0) {
    $selected = array_slice($selected, 0, (int) $opts['limit']);
}

if ('list' === $opts['mode']) {
    echo implode("\n", $selected), "\n";
    echo 'php-src-phpt: listed ' . count($selected) . '/' . count($all) . " cases ({$label})\n";
    exit(0);
}

$outDir = $opts['out_dir'] ?: ($repoRoot . '/build/php-src-phpt/' . preg_replace('/[^A-Za-z0-9._-]+/', '_', $label) . '-' . $backend);
$baselineDir = $opts['baseline_dir'] ?: ($repoRoot . '/test/php-src/baselines');
$baselineKey = preg_replace('/[^A-Za-z0-9._-]+/', '_', ($opts['corpus'] ?: $label) . '-' . $backend);

$runner = new PhpSrcPhptRunner($repoRoot, $backend, (int) $opts['timeout']);

$pass = [];
$fail = [];
$skip = [];
$bork = [];
$executed = [];

foreach ($selected as $name) {
    if (null !== $multiMap) {
        // Rebuild a one-file temporary cases root pointing at the real phpt path's directory.
        $phpt = $multiMap[$name] ?? null;
        if (null === $phpt || !is_file($phpt)) {
            $bork[] = $name;
            fwrite(STDOUT, "BORK {$name} missing\n");
            continue;
        }
        $oneRoot = dirname($phpt);
        $oneName = basename($phpt, '.phpt');
        // Use relative name from oneRoot; store canonical full name in sets.
        $result = $runner->runOne($oneRoot, $oneName);
        $canon = $name;
    } else {
        $result = $runner->runOne($casesRoot, $name);
        $canon = $name;
    }

    $status = $result['status'];
    if ('skip' === $status) {
        $skip[] = $canon;
        fwrite(STDOUT, "SKIP {$canon}\n");
        continue;
    }
    $executed[] = $canon;
    if ('pass' === $status) {
        $pass[] = $canon;
        fwrite(STDOUT, "PASS {$canon}\n");
    } elseif ('fail' === $status) {
        $fail[] = $canon;
        fwrite(STDOUT, "FAIL {$canon}" . (null !== $result['detail'] ? ' — ' . $result['detail'] : '') . "\n");
    } else {
        $bork[] = $canon;
        fwrite(STDOUT, "BORK {$canon}" . (null !== $result['detail'] ? ' — ' . $result['detail'] : '') . "\n");
    }
}

sort($pass, SORT_STRING);
sort($fail, SORT_STRING);
sort($skip, SORT_STRING);
sort($bork, SORT_STRING);
sort($executed, SORT_STRING);

$failAndBork = array_values(array_unique(array_merge($fail, $bork)));
sort($failAndBork, SORT_STRING);

if (!is_dir($outDir) && !mkdir($outDir, 0755, true) && !is_dir($outDir)) {
    fwrite(STDERR, "php-src-phpt: cannot create {$outDir}\n");
    exit(2);
}
php_src_phpt_write_lines($outDir . '/executed', $executed);
php_src_phpt_write_lines($outDir . '/failing', $failAndBork);
php_src_phpt_write_lines($outDir . '/skipped', $skip);
php_src_phpt_write_lines($outDir . '/passing', $pass);

$summary = [
    'generated_at' => gmdate('c'),
    'issue' => 36381,
    'backend' => $backend,
    'label' => $label,
    'executed' => count($executed),
    'pass' => count($pass),
    'fail' => count($fail),
    'skip' => count($skip),
    'bork' => count($bork),
    'top_failures' => array_slice($failAndBork, 0, 25),
];
file_put_contents($outDir . '/summary.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

echo sprintf(
    "php-src-phpt: %s backend=%s executed=%d pass=%d fail=%d skip=%d bork=%d\n",
    $label,
    $backend,
    count($executed),
    count($pass),
    count($fail),
    count($skip),
    count($bork)
);

if ($opts['scoreboard']) {
    php_src_phpt_write_scoreboard($repoRoot, $summary);
    echo "php-src-phpt: wrote docs/pages/php-src.html\n";
}

if ('collect' === $opts['mode']) {
    if (!is_dir($baselineDir) && !mkdir($baselineDir, 0755, true) && !is_dir($baselineDir)) {
        fwrite(STDERR, "php-src-phpt: cannot create {$baselineDir}\n");
        exit(2);
    }
    php_src_phpt_write_lines($baselineDir . '/' . $baselineKey . '.failing', $failAndBork);
    php_src_phpt_write_lines($baselineDir . '/' . $baselineKey . '.executed', $executed);
    php_src_phpt_write_lines($baselineDir . '/' . $baselineKey . '.skipped', $skip);
    echo "php-src-phpt: collected baselines → {$baselineDir}/{$baselineKey}.*\n";
    // Collect with zero executed is still a failure (honesty).
    if (0 === count($executed) && 0 === count($skip)) {
        fwrite(STDERR, "php-src-phpt: collected empty executed+skipped — refusing\n");
        exit(2);
    }
    exit(0);
}

if ('diff' === $opts['mode']) {
    $baseFail = php_src_phpt_read_lines($baselineDir . '/' . $baselineKey . '.failing');
    $baseExec = php_src_phpt_read_lines($baselineDir . '/' . $baselineKey . '.executed');
    if (0 === count($baseExec) && 0 === count($baseFail)) {
        fwrite(STDERR, "php-src-phpt: missing baselines for {$baselineKey} under {$baselineDir} — run --collect first\n");
        exit(2);
    }
    // Only diff cases that completed on both sides (AGENTS.md).
    $both = array_values(array_intersect($executed, $baseExec));
    $curFail = array_values(array_intersect($failAndBork, $both));
    $oldFail = array_values(array_intersect($baseFail, $both));
    $regressed = array_values(array_diff($curFail, $oldFail));
    $fixed = array_values(array_diff($oldFail, $curFail));
    echo 'REGRESSED (' . count($regressed) . "):\n";
    foreach ($regressed as $n) {
        echo "  {$n}\n";
    }
    echo 'FIXED (' . count($fixed) . "):\n";
    foreach ($fixed as $n) {
        echo "  {$n}\n";
    }
    if (count($regressed) > 0) {
        fwrite(STDERR, "php-src-phpt: DIFF FAIL — regressions present\n");
        exit(1);
    }
    echo "php-src-phpt: DIFF OK — no regressions vs {$baselineKey}\n";
    exit(0);
}

// Plain run: exit non-zero on fail/bork (useful for tiny sample corpus).
exit((count($fail) + count($bork)) > 0 ? 1 : 0);
