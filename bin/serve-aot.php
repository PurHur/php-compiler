#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * HTTP dev server that runs a precompiled AOT CGI binary per request (issues #213, #50).
 *
 * Usage: php bin/serve-aot.php [host:port] [docroot] [--binary path]
 */

require __DIR__.'/../src/tokenizer-compat.php';
require __DIR__.'/../src/yay-php8-compat.php';
require __DIR__.'/../src/llvm-env.php';
require __DIR__.'/../vendor/autoload.php';

use PHPCompiler\Web\DevServer;
use PHPCompiler\Web\ProjectManifest;

$listen = '127.0.0.1:8080';
$docroot = null;
$binary = null;
$args = array_slice($argv, 1);

while ([] !== $args) {
    $arg = array_shift($args);
    if ('--binary' === $arg) {
        if ([] === $args) {
            fwrite(STDERR, "serve-aot: --binary requires a path\n");
            exit(1);
        }
        $binary = array_shift($args);
        continue;
    }
    if (null === $docroot && str_contains($arg, ':') && preg_match('#:\d+$#', $arg)) {
        $listen = $arg;
        continue;
    }
    if (null === $docroot) {
        $docroot = $arg;
        continue;
    }
    fwrite(STDERR, "Unexpected argument: {$arg}\n");
    exit(1);
}

$docroot = $docroot ?? getcwd();
$resolvedBinary = ProjectManifest::resolveBinaryPath($docroot, $binary);
if (null === $resolvedBinary) {
    fwrite(STDERR, "serve-aot: no AOT binary found. Build with: phpc build -o .phpc/bin/app entry.php\n");
    exit(1);
}

$repoRoot = realpath(__DIR__.'/..') ?: __DIR__.'/..';
$env = buildProcessEnv($repoRoot);

fwrite(STDERR, "PHP-Compiler serve-aot: binary {$resolvedBinary}\n");

DevServer::run($listen, $docroot, static function (string $script, array $cgiEnv) use ($resolvedBinary, $env): array {
    return runAotBinary($resolvedBinary, $env, $cgiEnv);
});

/**
 * @param array<string, string> $env
 *
 * @return array{0: int, 1: string, 2: string, 3: list<string>}
 */
function runAotBinary(string $binary, array $env, array $cgiEnv): array
{
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $procEnv = $cgiEnv;
    foreach (['PATH', 'LD_LIBRARY_PATH', 'HOME', 'LANG', 'LC_ALL'] as $key) {
        if (isset($env[$key]) && '' !== $env[$key]) {
            $procEnv[$key] = $env[$key];
        }
    }
    if (!isset($procEnv['PATH'])) {
        $procEnv['PATH'] = '/usr/bin:/bin';
    }

    $proc = proc_open([$binary], $descriptorSpec, $pipes, null, $procEnv);
    if (!is_resource($proc)) {
        throw new \RuntimeException('Failed to start AOT binary: '.$binary);
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    if (0 !== $code) {
        throw new \RuntimeException(
            'AOT binary exited with code '.$code
            .': '.trim($stderr !== false ? $stderr : '')
            .(false !== $stdout && '' !== trim($stdout) ? ' | stdout: '.trim($stdout) : '')
        );
    }

    return DevServer::parseCgiOutput($stdout !== false ? $stdout : '');
}

/**
 * @return array<string, string>
 */
function buildProcessEnv(string $repoRoot): array
{
    $env = [];
    foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
        if (is_string($key) && is_string($value)) {
            $env[$key] = $value;
        }
    }
    $llvmDir = $repoRoot.'/.llvm';
    if (is_file($llvmDir.'/libLLVM-9.so.1')) {
        $prefix = realpath($llvmDir) ?: $llvmDir;
        $env['PHP_COMPILER_LLVM_PATH'] = $prefix;
        $ld = $env['LD_LIBRARY_PATH'] ?? '';
        $env['LD_LIBRARY_PATH'] = '' === $ld ? $prefix : $prefix.':'.$ld;
        $path = $env['PATH'] ?? '';
        $env['PATH'] = '' === $path ? $prefix : $prefix.':'.$path;
    }

    return $env;
}
