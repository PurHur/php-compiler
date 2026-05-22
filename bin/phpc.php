#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Unified CLI for php-compiler (serve, run, build, test).
 *
 * Usage:
 *   phpc serve [host:port] [docroot]
 *   phpc serve --aot [host:port] [docroot] [--binary path]
 *   phpc run [-q 'name=World'] [-p 'field=val'] script.php [args...]
 *   phpc build [-o outfile] entry.php
 *   phpc build --project [dir]                 AOT compile from phpc.json entry/binary
 *   phpc lint [-r 'code'] [--json] entry.php
 *   phpc lint --project <entry.php> [--json]
 *   phpc lint --all <dir-or-file> [--json]
 *   phpc init [--force] [target-dir]
 *   phpc test [--fast] [-- phpunit/ci-local args...]
 *   phpc doctor                                  Probe PHP, LLVM, deps, loopback (issue #253)
 *   phpc validate-manifest [dir]                 Validate phpc.json schema and paths (issue #263)
 */

$repoRoot = realpath(__DIR__.'/..') ?: __DIR__.'/..';
$php = phpCommand();
$args = $argv;
array_shift($args);

if ([] === $args || in_array($args[0], ['-h', '--help', 'help'], true)) {
    fwrite(STDOUT, <<<'HELP'
php-compiler CLI

  phpc serve [host:port] [docroot]              Start HTTP dev server (VM)
  phpc serve --aot [host:port] [docroot]        Serve precompiled AOT binary (CGI env)
      [--binary path]                           Explicit binary or phpc.json "binary"
  phpc run <script.php> [vm.php flags...]      Run a script in the VM
      -q 'name=World'                          CGI-style QUERY_STRING → $_GET
      -p 'field=value'                         CGI-style POST body → $_POST
      Example: phpc run -q 'name=Dev' examples/001-SimpleWeb/example.php
  phpc build [-o out] <entry.php>               AOT compile to a native binary
  phpc build --project [dir]                    Build from phpc.json entry + binary paths
  phpc lint [-r 'code'] [--json] <entry.php>    Report unsupported syntax (line-accurate)
  phpc lint --project <entry.php> [--json]    Entry + literal include/require chain
  phpc lint --all <dir-or-file> [--json]      All .php under a tree (aggregated)
      --json fields: file, line, kind, message, issue, issue_url (when tracked)
  phpc init [--force] [target-dir]              Scaffold phpc.json + public/index.php
  phpc test [--fast] [args...]                  Run ci-local.sh (full) or ci-fast.sh (no LLVM)
  phpc doctor                                   Probe environment for full local CI
  phpc validate-manifest [dir]                  Validate phpc.json (default: cwd)

HELP);
    exit([] === $args ? 1 : 0);
}

$command = array_shift($args);

switch ($command) {
    case 'serve':
        $aot = false;
        $serveArgs = [];
        while ([] !== $args) {
            $arg = array_shift($args);
            if ('--aot' === $arg) {
                $aot = true;
                continue;
            }
            $serveArgs[] = $arg;
        }
        $script = $aot ? $repoRoot.'/bin/serve-aot.php' : $repoRoot.'/bin/serve.php';
        exit(runProcess(array_merge($php, array_merge([$script], $serveArgs)), $repoRoot));

    case 'run':
        if ([] === $args) {
            fwrite(STDERR, "phpc run: missing script.php\n");
            exit(1);
        }
        exit(runProcess(array_merge($php, [$repoRoot.'/bin/vm.php'], $args), $repoRoot));

    case 'build':
        if ([] !== $args && '--project' === $args[0]) {
            array_shift($args);
            $projectDir = $args[0] ?? '.';
            exit(buildFromProject($repoRoot, $php, $projectDir));
        }
        if ([] === $args) {
            fwrite(STDERR, "phpc build: missing entry.php (or use: phpc build --project [dir])\n");
            exit(1);
        }
        exit(runProcess(array_merge($php, [$repoRoot.'/bin/compile.php'], $args), $repoRoot));

    case 'lint':
        exit(runProcess(array_merge($php, [$repoRoot.'/bin/lint.php'], $args), $repoRoot));

    case 'init':
        exit(runProcess(array_merge($php, [$repoRoot.'/bin/init.php'], $args), $repoRoot));

    case 'test':
        $fast = false;
        if ([] !== $args && in_array($args[0], ['--fast', 'fast'], true)) {
            $fast = true;
            array_shift($args);
        }
        $testScript = $repoRoot.'/script/'.($fast ? 'ci-fast.sh' : 'ci-local.sh');
        if (!is_executable($testScript)) {
            fwrite(STDERR, "phpc test: {$testScript} is not executable\n");
            exit(1);
        }
        exit(runProcess(array_merge([$testScript], $args), $repoRoot));

    case 'doctor':
        if (!is_file($repoRoot.'/vendor/autoload.php')) {
            fwrite(STDERR, "phpc doctor: run composer install first\n");
            exit(1);
        }
        require $repoRoot.'/vendor/autoload.php';
        exit(\PHPCompiler\Doctor::run($repoRoot));

    case 'validate-manifest':
        if (!is_file($repoRoot.'/vendor/autoload.php')) {
            fwrite(STDERR, "phpc validate-manifest: run composer install first\n");
            exit(1);
        }
        require $repoRoot.'/vendor/autoload.php';
        $targetDir = $args[0] ?? getcwd();
        if (false === $targetDir || '' === $targetDir) {
            fwrite(STDERR, "phpc validate-manifest: cannot resolve target directory\n");
            exit(1);
        }
        $errors = \PHPCompiler\Web\ManifestValidator::validate($targetDir);
        if ([] === $errors) {
            fwrite(STDOUT, "phpc.json OK: {$targetDir}\n");
            exit(0);
        }
        foreach ($errors as $message) {
            fwrite(STDERR, $message."\n");
        }
        exit(1);

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
 * @param list<string> $php
 */
function buildFromProject(string $repoRoot, array $php, string $projectDir): int
{
    if (!is_file($repoRoot.'/vendor/autoload.php')) {
        fwrite(STDERR, "phpc build --project: run composer install first\n");
        return 1;
    }
    require $repoRoot.'/vendor/autoload.php';

    $errors = \PHPCompiler\Web\ManifestValidator::validateForBuild($projectDir);
    if ([] !== $errors) {
        foreach ($errors as $message) {
            fwrite(STDERR, $message."\n");
        }
        return 1;
    }

    $entry = \PHPCompiler\Web\ProjectManifest::resolveEntryPath($projectDir);
    $output = \PHPCompiler\Web\ProjectManifest::resolveBinaryOutputPath($projectDir);
    if (null === $entry || null === $output) {
        fwrite(STDERR, "phpc build --project: could not resolve entry or binary from phpc.json\n");
        return 1;
    }

    $parent = dirname($output);
    if ('' !== $parent && !is_dir($parent) && !mkdir($parent, 0777, true) && !is_dir($parent)) {
        fwrite(STDERR, "phpc build --project: cannot create output directory: {$parent}\n");
        return 1;
    }

    $includes = \PHPCompiler\Web\ProjectManifest::resolveIncludePaths($projectDir);
    $cmd = array_merge($php, [$repoRoot.'/bin/compile.php', '-o', $output]);
    foreach ($includes as $includePath) {
        $cmd[] = '--include';
        $cmd[] = $includePath;
    }
    $cmd[] = $entry;

    return runProcess($cmd, $repoRoot);
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
