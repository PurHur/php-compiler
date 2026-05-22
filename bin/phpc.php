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
 *   phpc build --project [dir] [--dry-run]     AOT compile from phpc.json entry/binary
 *   phpc deploy [dir] -o <dist> [--from-build]  Bundle binary, public/, assets/, phpc.json
 *   phpc cgi [binary]                           CGI wrapper for AOT binary (issue #665)
 *   phpc lint [-r 'code'] [--json] entry.php
 *   phpc lint --project <entry.php> [--json]
 *   phpc lint --all <dir-or-file> [--json]
 *   phpc init [--profile default|miniwebapp] [--force] [target-dir]
 *   phpc test [--fast] [-- phpunit/ci-local args...]
 *   phpc doctor [--gates] [--no-lint]             Probe env; --gates prints MiniWebApp ladder (#657)
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
  phpc build --project [dir] [--dry-run]        Build from phpc.json entry + binary paths
      --dry-run                                 List entry + includes graph; exit before LLVM
      --verbose                                 Print compile-unit graph; keep full LLVM stderr on failure
      PHPC_BUILD_VERBOSE=1                      Same as --verbose
      PHPC_INVOKE_CWD=<dir>                     Set by ./phpc wrapper; relative paths use this base
  phpc deploy [dir] -o <dist>                   Package AOT binary + manifest trees into dist/
      --from-build                              Require existing binary (skip phpc build --project)
  phpc cgi [binary]                             Run AOT binary under CGI env (stdin → REQUEST_BODY)
      PHPC_DEPLOY_ROOT=<dist>                   Resolve bin/app from deploy bundle when binary omitted
  phpc lint [-r 'code'] [--json] <entry.php>    Report unsupported syntax (line-accurate)
  phpc lint --project <entry.php> [--json]    Entry + literal include/require chain
  phpc lint --all <dir-or-file> [--json]      All .php under a tree (aggregated)
      --json fields: file, line, kind, message, issue, issue_url (when tracked)
  phpc init [--profile default|miniwebapp] [--force] [target-dir]
                                              Scaffold web project (default hello or miniwebapp)
  phpc test [--fast] [args...]                  Run ci-local.sh (full) or ci-fast.sh (no LLVM)
  phpc doctor [--gates] [--no-lint]             Probe environment; --gates: MiniWebApp CI ladder
  phpc validate-manifest [dir]                  Validate phpc.json (default: cwd)

HELP);
    exit([] === $args ? 1 : 0);
}

$command = array_shift($args);

switch ($command) {
    case 'serve':
        if (!is_file($repoRoot.'/vendor/autoload.php')) {
            fwrite(STDERR, "phpc serve: run composer install first\n");
            exit(1);
        }
        require $repoRoot.'/vendor/autoload.php';
        $aot = false;
        $serveArgs = [];
        while ([] !== $args) {
            $arg = array_shift($args);
            if ('--aot' === $arg) {
                $aot = true;
                continue;
            }
            if ('--binary' === $arg && [] !== $args) {
                $serveArgs[] = $arg;
                $serveArgs[] = \PHPCompiler\Cli\InvokeCwd::resolve(array_shift($args));
                continue;
            }
            $serveArgs[] = $arg;
        }
        if ([] !== $serveArgs) {
            $last = array_key_last($serveArgs);
            if (is_int($last) && !str_contains((string) $serveArgs[$last], ':')) {
                $serveArgs[$last] = \PHPCompiler\Cli\InvokeCwd::resolve((string) $serveArgs[$last]);
            }
        }
        $script = $aot ? $repoRoot.'/bin/serve-aot.php' : $repoRoot.'/bin/serve.php';
        exit(runProcess(array_merge($php, array_merge([$script], $serveArgs)), $repoRoot));

    case 'run':
        if ([] === $args) {
            fwrite(STDERR, "phpc run: missing script.php\n");
            exit(1);
        }
        exit(runProcess(array_merge($php, [$repoRoot.'/bin/vm.php'], $args), $repoRoot));

    case 'deploy':
        if (!is_file($repoRoot.'/vendor/autoload.php')) {
            fwrite(STDERR, "phpc deploy: run composer install first\n");
            exit(1);
        }
        require $repoRoot.'/vendor/autoload.php';
        exit(deployFromProject($repoRoot, phpCommand(), $args));

    case 'cgi':
        if (!is_file($repoRoot.'/vendor/autoload.php')) {
            fwrite(STDERR, "phpc cgi: run composer install first\n");
            exit(1);
        }
        $cgiArgs = [];
        while ([] !== $args) {
            $cgiArgs[] = array_shift($args);
        }
        exit(runProcess(array_merge($php, array_merge([$repoRoot.'/bin/cgi-aot.php'], $cgiArgs)), $repoRoot));

    case 'build':
        if ([] !== $args && '--project' === $args[0]) {
            array_shift($args);
            $dryRun = false;
            $verbose = false;
            $projectDir = '.';
            foreach ($args as $arg) {
                if ('--dry-run' === $arg) {
                    $dryRun = true;
                    continue;
                }
                if ('--verbose' === $arg) {
                    $verbose = true;
                    continue;
                }
                if (str_starts_with($arg, '-')) {
                    fwrite(STDERR, "phpc build --project: unknown option: {$arg}\n");
                    exit(1);
                }
                $projectDir = $arg;
            }
            exit(buildFromProject($repoRoot, $php, $projectDir, $dryRun, $verbose));
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
        $gates = false;
        $noLint = false;
        foreach ($args as $arg) {
            if ('--gates' === $arg) {
                $gates = true;
                continue;
            }
            if ('--no-lint' === $arg) {
                $noLint = true;
                continue;
            }
            fwrite(STDERR, "phpc doctor: unknown option: {$arg}\n");
            exit(1);
        }
        if ($gates) {
            exit(\PHPCompiler\Doctor::runGates($repoRoot, $noLint));
        }
        exit(\PHPCompiler\Doctor::run($repoRoot));

    case 'validate-manifest':
        if (!is_file($repoRoot.'/vendor/autoload.php')) {
            fwrite(STDERR, "phpc validate-manifest: run composer install first\n");
            exit(1);
        }
        require $repoRoot.'/vendor/autoload.php';
        $targetDir = \PHPCompiler\Cli\InvokeCwd::resolve($args[0] ?? '.');
        if ('' === $targetDir) {
            fwrite(STDERR, "phpc validate-manifest: cannot resolve target directory\n");
            exit(1);
        }
        $errors = \PHPCompiler\Web\ManifestValidator::validate($targetDir);
        if ([] === $errors) {
            fwrite(STDOUT, "phpc.json OK: {$targetDir}\n");
            exit(0);
        }
        $resolved = realpath($targetDir);
        $manifestLabel = false !== $resolved ? $resolved.'/phpc.json' : $targetDir.'/phpc.json';
        fwrite(STDERR, "phpc.json: {$manifestLabel}\n");
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
 * @param list<string> $args CLI args after "deploy"
 */
function deployFromProject(string $repoRoot, array $php, array $args): int
{
    $fromBuild = false;
    $outputDir = null;
    $projectDir = '.';

    $i = 0;
    $argc = count($args);
    while ($i < $argc) {
        $arg = $args[$i];
        if ('--from-build' === $arg) {
            ++$i;
            continue;
        }
        if ('-o' === $arg || '--output' === $arg) {
            if ($i + 1 >= $argc) {
                fwrite(STDERR, "phpc deploy: missing value for {$arg}\n");
                return 1;
            }
            $outputDir = $args[$i + 1];
            $i += 2;
            continue;
        }
        if (!str_starts_with($arg, '-')) {
            $projectDir = $arg;
            ++$i;
            continue;
        }
        fwrite(STDERR, "phpc deploy: unknown option: {$arg}\n");
        return 1;
    }

    if (null === $outputDir || '' === $outputDir) {
        fwrite(STDERR, "phpc deploy: required -o <dist>\n");
        return 1;
    }

    $projectDir = \PHPCompiler\Cli\InvokeCwd::resolve($projectDir);
    $outputDir = \PHPCompiler\Cli\InvokeCwd::resolve($outputDir);
    $projectReal = realpath($projectDir);
    if (false === $projectReal) {
        fwrite(STDERR, "phpc deploy: project directory not found: {$projectDir}\n");
        return 1;
    }

    $binary = \PHPCompiler\Web\ProjectManifest::resolveBinaryOutputPath($projectReal);
    if (null === $binary) {
        fwrite(STDERR, "phpc deploy: could not resolve binary from phpc.json\n");
        return 1;
    }

    if (!is_file($binary)) {
        if ($fromBuild) {
            fwrite(STDERR, "phpc deploy: binary not found: {$binary}\n");
            return 1;
        }
        $buildCode = buildFromProject($repoRoot, $php, $projectReal, false);
        if (0 !== $buildCode) {
            return $buildCode;
        }
        if (!is_file($binary)) {
            fwrite(STDERR, "phpc deploy: build completed but binary missing: {$binary}\n");
            return 1;
        }
    }

    $errors = \PHPCompiler\Web\ProjectDeploy::deploy($projectReal, $outputDir, false);
    if ([] !== $errors) {
        foreach ($errors as $message) {
            fwrite(STDERR, $message."\n");
        }
        return 1;
    }

    $dist = realpath($outputDir) ?: $outputDir;
    fwrite(STDOUT, "Deployed to {$dist}\n");

    return 0;
}

/**
 * @param list<string> $php
 */
function buildFromProject(
    string $repoRoot,
    array $php,
    string $projectDir,
    bool $dryRun = false,
    bool $verbose = false
): int {
    if (!is_file($repoRoot.'/vendor/autoload.php')) {
        fwrite(STDERR, "phpc build --project: run composer install first\n");
        return 1;
    }
    require $repoRoot.'/vendor/autoload.php';
    $projectDir = \PHPCompiler\Cli\InvokeCwd::resolve($projectDir);

    $verbose = \PHPCompiler\Cli\PhpcBuild::verboseEnabled($verbose);

    if ($dryRun) {
        return \PHPCompiler\AOT\ProjectGraph::preflight($projectDir);
    }

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

    if ($verbose) {
        \PHPCompiler\Cli\PhpcBuild::emitVerboseProjectGraph($projectDir);
    }

    $includes = \PHPCompiler\Web\ProjectManifest::resolveIncludePaths($projectDir);
    $compileArgv = ['-o', $output];
    foreach ($includes as $includePath) {
        $compileArgv[] = '--include';
        $compileArgv[] = $includePath;
    }
    $compileArgv[] = $entry;

    $result = \PHPCompiler\Cli\PhpcBuild::runCompile(
        $php,
        $repoRoot,
        $repoRoot.'/bin/compile.php',
        $repoRoot,
        $compileArgv
    );
    \PHPCompiler\Cli\PhpcBuild::emitBuildOutput($result, $verbose);

    return $result['exit'];
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
