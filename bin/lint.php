#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Lint PHP sources for constructs the static compiler cannot lower yet.
 *
 * Usage:
 *   bin/lint.php [-r 'code'] [--json] <file.php>
 *   bin/lint.php --project <entry.php> [--json]
 *   bin/lint.php --all <dir-or-file> [--json]
 *   bin/lint.php --bootstrap-inventory [--check] [--json]
 *   phpc lint ...
 */

use PHPCompiler\Lint\Issue;
use PHPCompiler\Lint\Linter;

require __DIR__.'/../src/tokenizer-compat.php';
require __DIR__.'/../src/yay-php8-compat.php';
require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/../script/bootstrap-lib.php';

// Fast frontend paths: use-chain Simplifier is now the compile default (#23056);
// still force it here so lint stays fast even if PHPCFG_SIMPLIFIER_LEGACY=1 is
// set in the environment. Worklist type resolver remains lint-only (#16077) —
// resolution ORDER can affect AOT. PHP_COMPILER_LINT_FRONTEND_FAST=0 opts out.
if ('0' !== getenv('PHP_COMPILER_LINT_FRONTEND_FAST')) {
    putenv('PHPCFG_SIMPLIFIER_USECHAIN=1');
    putenv('PHPCFG_SIMPLIFIER_LEGACY');
    putenv('PHPTYPES_RESOLVER_WORKLIST=1');
}

$json = false;
$check = false;
$code = null;
$filename = null;
$mode = 'file';
$args = array_slice($argv, 1);

while ([] !== $args) {
    $arg = array_shift($args);
    if ('--json' === $arg) {
        $json = true;
        continue;
    }
    if ('--check' === $arg) {
        $check = true;
        continue;
    }
    if ('--all' === $arg) {
        $mode = 'all';
        continue;
    }
    if ('--project' === $arg) {
        $mode = 'project';
        continue;
    }
    if ('--bootstrap-inventory' === $arg) {
        $mode = 'bootstrap-inventory';
        continue;
    }
    if ('--worker-stdin' === $arg) {
        $mode = 'worker-stdin';
        continue;
    }
    if ('-r' === $arg) {
        if ([] === $args) {
            fwrite(STDERR, "Option -r requires code argument\n");
            exit(1);
        }
        $snippet = array_shift($args);
        $code = str_starts_with(ltrim($snippet), '<?') ? $snippet : '<?php '.$snippet;
        $filename = 'Standard input code';
        $mode = 'file';
        continue;
    }
    if (str_starts_with($arg, '-')) {
        fwrite(STDERR, "Unknown option: {$arg}\n");
        exit(1);
    }
    if (null !== $filename) {
        fwrite(STDERR, "Extra argument: {$arg}\n");
        exit(1);
    }
    if ('file' === $mode && !is_file($arg) && !is_dir($arg)) {
        fwrite(STDERR, "Could not open file {$arg}\n");
        exit(1);
    }
    if ('all' === $mode && !is_file($arg) && !is_dir($arg)) {
        fwrite(STDERR, "Not a file or directory: {$arg}\n");
        exit(1);
    }
    if ('project' === $mode && !is_file($arg)) {
        fwrite(STDERR, "Could not open file {$arg}\n");
        exit(1);
    }
    $filename = $arg;
    if ('file' === $mode) {
        $code = is_file($arg) ? (string) file_get_contents($arg) : null;
    }
}

if ('bootstrap-inventory' !== $mode && 'worker-stdin' !== $mode && null === $filename) {
    fwrite(STDERR, "Usage: lint.php [-r 'code'] [--json] <file.php>\n");
    fwrite(STDERR, "       lint.php --project <entry.php> [--json]\n");
    fwrite(STDERR, "       lint.php --all <dir-or-file> [--json]\n");
    fwrite(STDERR, "       lint.php --bootstrap-inventory [--check] [--json]\n");
    exit(1);
}

$linter = new Linter();

try {
    if ('worker-stdin' === $mode) {
        // Hidden fan-out mode for --bootstrap-inventory: newline-separated
        // absolute paths on stdin → JSON {rel: kinds} on stdout (#16077).
        $repoRoot = realpath(__DIR__.'/..') ?: __DIR__.'/..';
        $byFile = [];
        while (false !== ($line = fgets(STDIN))) {
            $path = trim($line);
            if ('' === $path || !is_file($path)) {
                continue;
            }
            $fileIssues = $linter->lintFileStandalone($path);
            $rel = substr($path, strlen($repoRoot) + 1);
            $kinds = [];
            foreach ($fileIssues as $issue) {
                $kinds[$issue->kind] = true;
            }
            ksort($kinds, SORT_STRING);
            $byFile[$rel] = array_keys($kinds);
        }
        fwrite(STDOUT, json_encode(['files' => $byFile], JSON_UNESCAPED_SLASHES)."\n");
        exit(0);
    }
    if ('bootstrap-inventory' === $mode) {
        $repoRoot = realpath(__DIR__.'/..') ?: __DIR__.'/..';
        $inventoryPaths = bootstrapVmPathPhpFiles($repoRoot);

        // Per-file result cache + parallel workers (#16077): the sequential
        // full-inventory lint took 2h+; a warm cache answers in seconds and a
        // cold run fans out over CPU cores. Keyed by file content hash + a
        // linter fingerprint so linter changes invalidate everything.
        $cacheEnabled = '0' !== getenv('PHP_COMPILER_LINT_CACHE');
        $cachePath = $repoRoot.'/build/lint-cache/bootstrap-inventory.json';
        $linterFingerprint = (static function () use ($repoRoot): string {
            $parts = [(string) getenv('PHP_COMPILER_LINT_CACHE_SALT')];
            foreach ([
                __FILE__,
                $repoRoot.'/lib/Lint/Linter.php',
                $repoRoot.'/composer.lock',
                $repoRoot.'/docs/unsupported-syntax.md',
            ] as $file) {
                $parts[] = $file.':'.@filemtime($file).':'.@filesize($file);
            }

            return substr(hash('sha256', implode("\n", $parts)), 0, 16);
        })();

        $cache = [];
        if ($cacheEnabled && is_readable($cachePath)) {
            $decoded = json_decode((string) file_get_contents($cachePath), true);
            if (\is_array($decoded) && ($decoded['fingerprint'] ?? null) === $linterFingerprint
                && \is_array($decoded['entries'] ?? null)) {
                $cache = $decoded['entries'];
            }
        }

        $byFile = [];
        $pending = [];
        $freshEntries = [];
        foreach ($inventoryPaths as $path) {
            $rel = substr($path, strlen($repoRoot) + 1);
            $hash = sha1_file($path) ?: '';
            $entry = $cache[$rel] ?? null;
            if (\is_array($entry) && ($entry['hash'] ?? null) === $hash && \is_array($entry['kinds'] ?? null)) {
                $freshEntries[$rel] = $entry;
                if ([] !== $entry['kinds']) {
                    $byFile[$rel] = $entry['kinds'];
                }

                continue;
            }
            $pending[$rel] = ['path' => $path, 'hash' => $hash];
        }

        if ([] !== $pending) {
            $jobs = max(1, (int) (getenv('PHP_COMPILER_LINT_JOBS') ?: (function_exists('shell_exec')
                ? max(1, ((int) shell_exec('nproc 2>/dev/null')) - 2)
                : 4)));
            $jobs = min($jobs, count($pending));
            $chunks = array_fill(0, $jobs, []);
            $i = 0;
            foreach ($pending as $rel => $info) {
                $chunks[$i % $jobs][] = $info['path'];
                ++$i;
            }
            $procs = [];
            foreach ($chunks as $idx => $chunk) {
                if ([] === $chunk) {
                    continue;
                }
                $proc = proc_open(
                    [PHP_BINARY, __FILE__, '--worker-stdin'],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
                    $pipes
                );
                if (!\is_resource($proc)) {
                    continue;
                }
                fwrite($pipes[0], implode("\n", $chunk)."\n");
                fclose($pipes[0]);
                $procs[$idx] = ['proc' => $proc, 'out' => $pipes[1]];
            }
            foreach ($procs as $p) {
                $out = stream_get_contents($p['out']);
                fclose($p['out']);
                proc_close($p['proc']);
                $decoded = json_decode((string) $out, true);
                foreach (($decoded['files'] ?? []) as $rel => $kinds) {
                    if (!\is_array($kinds)) {
                        continue;
                    }
                    $freshEntries[$rel] = ['hash' => $pending[$rel]['hash'] ?? '', 'kinds' => $kinds];
                    if ([] !== $kinds) {
                        $byFile[$rel] = $kinds;
                    }
                }
            }
            // Any pending file a worker failed to report gets linted inline so
            // a worker crash cannot silently drop findings.
            foreach ($pending as $rel => $info) {
                if (isset($freshEntries[$rel])) {
                    continue;
                }
                $fileIssues = $linter->lintFileStandalone($info['path']);
                $kinds = [];
                foreach ($fileIssues as $issue) {
                    $kinds[$issue->kind] = true;
                }
                ksort($kinds, SORT_STRING);
                $freshEntries[$rel] = ['hash' => $info['hash'], 'kinds' => array_keys($kinds)];
                if ([] !== $kinds) {
                    $byFile[$rel] = array_keys($kinds);
                }
            }
        }

        if ($cacheEnabled) {
            $cacheDir = \dirname($cachePath);
            if (!is_dir($cacheDir)) {
                @mkdir($cacheDir, 0755, true);
            }
            @file_put_contents($cachePath, json_encode([
                'fingerprint' => $linterFingerprint,
                'entries' => $freshEntries,
            ])."\n");
        }
        ksort($byFile, SORT_STRING);
        if ($json) {
            fwrite(STDOUT, json_encode(['files' => $byFile], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        } else {
            foreach ($byFile as $rel => $kinds) {
                fwrite(STDOUT, $rel.': '.implode(', ', $kinds)."\n");
            }
            $scanned = count($inventoryPaths);
            if ([] === $byFile) {
                fwrite(STDOUT, "bootstrap-inventory: 0 files with unsupported syntax ({$scanned} scanned)\n");
            } else {
                fwrite(STDOUT, 'bootstrap-inventory: '.count($byFile)." file(s) with unsupported syntax ({$scanned} scanned)\n");
            }
        }
        exit($check && [] !== $byFile ? 1 : 0);
    }
    if ('all' === $mode) {
        $issues = $linter->lintAll($filename);
    } elseif ('project' === $mode) {
        $issues = $linter->lintProject($filename);
    } elseif (null !== $code) {
        $issues = $linter->lintSource($code, $filename);
    } else {
        $issues = $linter->lintFile($filename);
    }
} catch (\InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage()."\n");
    exit(1);
}

foreach ($linter->consumeDynamicIncludeWarnings() as $warning) {
    fwrite(STDERR, "warning: {$warning}\n");
}

if ($json) {
    $payload = array_map(static fn (Issue $i) => $i->toArray(), $issues);
    fwrite(STDOUT, json_encode(['issues' => $payload], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
} else {
    foreach ($issues as $issue) {
        fwrite(STDOUT, $issue->formatHuman()."\n");
    }
}

exit([] === $issues ? 0 : 1);
