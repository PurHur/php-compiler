#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Unified CLI for php-compiler (serve, run, build, test).
 *
 * Usage:
 *   phpc serve [host:port] [docroot]
 *   phpc run script.php [args...]
 *   phpc build [-o outfile] entry.php
 *   phpc test [-- phpunit/ci-local args...]
 */

$repoRoot = realpath(__DIR__.'/..') ?: __DIR__.'/..';
$php = phpCommand();
$args = $argv;
array_shift($args);

if ([] === $args || in_array($args[0], ['-h', '--help', 'help'], true)) {
    fwrite(STDOUT, <<<'HELP'
php-compiler CLI

  phpc serve [host:port] [docroot]   Start HTTP dev server (VM)
  phpc run <script.php> [args...]    Run a script in the VM
  phpc build [-o out] <entry.php>    AOT compile to a native binary
  phpc test [args...]                Run ./script/ci-local.sh

HELP);
    exit([] === $args ? 1 : 0);
}

$command = array_shift($args);

switch ($command) {
    case 'serve':
        $listen = $args[0] ?? '127.0.0.1:8080';
        $docroot = $args[1] ?? 'examples/001-SimpleWeb';
        exit(runProcess(array_merge($php, [$repoRoot.'/bin/serve.php', $listen, $docroot]), $repoRoot));

    case 'run':
        if ([] === $args) {
            fwrite(STDERR, "phpc run: missing script.php\n");
            exit(1);
        }
        exit(runProcess(array_merge($php, [$repoRoot.'/bin/vm.php'], $args), $repoRoot));

    case 'build':
        if ([] === $args) {
            fwrite(STDERR, "phpc build: missing entry.php\n");
            exit(1);
        }
        exit(runProcess(array_merge($php, [$repoRoot.'/bin/compile.php'], $args), $repoRoot));

    case 'test':
        $testScript = $repoRoot.'/script/ci-local.sh';
        if (!is_executable($testScript)) {
            fwrite(STDERR, "phpc test: {$testScript} is not executable\n");
            exit(1);
        }
        exit(runProcess(array_merge([$testScript], $args), $repoRoot));

    default:
        fwrite(STDERR, "Unknown command: {$command}\n");
        exit(1);
}

/**
 * @return list<string>
 */
function phpCommand(): array
{
    $phpEnv = getenv('PHP_COMPILER_PHP');
    if (false !== $phpEnv && '' !== $phpEnv) {
        return preg_split('/\s+/', $phpEnv) ?: [PHP_BINARY];
    }
    $cmd = [PHP_BINARY];
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

    return $cmd;
}

/**
 * @param list<string> $cmd
 */
function runProcess(array $cmd, string $cwd): int
{
    $descriptorSpec = [
        0 => STDIN,
        1 => STDOUT,
        2 => STDERR,
    ];
    $proc = proc_open($cmd, $descriptorSpec, $pipes, $cwd);
    if (!is_resource($proc)) {
        fwrite(STDERR, "Failed to start: ".implode(' ', $cmd)."\n");
        return 1;
    }
    $code = proc_close($proc);

    return is_int($code) ? $code : 1;
}
