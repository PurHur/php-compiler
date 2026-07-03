<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

use PHPCompiler\Runtime;
use PHPCompiler\Web\DeployRoot;
use PHPCompiler\Web\LiteralIncludeDiscovery;
use PHPCompiler\Web\SourceBundler;
use PHPCompiler\Web\Superglobals;

/**
 * True when bin/compile.php is building a user/test fixture binary (not bootstrap/self-host spine).
 */
/**
 * Self-host spine bundles: do not mega-concat via SourceBundler (OOM / invalid LLVM IR at
 * ~2465+ units; #8391, #8559). Native argv drivers compile the entry file and fold literal
 * includes incrementally via JIT IncludeHelper.
 */
function phpc_compile_skip_aot_bundle(string $normalized): bool
{
    if ('' === $normalized) {
        return false;
    }

    return str_contains($normalized, 'test/selfhost/compiler_lib_spine_smoke/main.php');
}

/** @deprecated alias for lint call sites */
function phpc_compile_skip_aot_bundle_for_lint(string $normalized): bool
{
    return phpc_compile_skip_aot_bundle($normalized);
}

function phpc_compile_is_user_script_aot(string $normalized): bool
{
    if ('' === $normalized || '-' === $normalized
        || 'Standard input code' === $normalized
        || 'Command line code' === $normalized) {
        return true;
    }
    if (str_contains($normalized, 'bootstrap-aot/')) {
        return false;
    }
    if (str_contains($normalized, 'selfhost/')) {
        return false;
    }
    if (str_contains($normalized, 'compile_driver.php')) {
        return false;
    }
    if (str_contains($normalized, 'compiler_unit_probe')) {
        return false;
    }

    return true;
}

function phpc_compile_ensure_repo_root_env(): void
{
    $existing = getenv('PHP_COMPILER_REPO_ROOT');
    if (is_string($existing) && '' !== $existing && is_readable($existing.'/bin/compile.php')) {
        return;
    }
    global $argv;
    /** @var list<string> $candidates */
    $candidates = [];
    $cwd = getcwd();
    if (is_string($cwd) && '' !== $cwd) {
        $candidates[] = $cwd;
    }
    if (isset($argv) && is_array($argv)) {
        foreach ($argv as $arg) {
            if (!is_string($arg)) {
                continue;
            }
            $norm = str_replace('\\', '/', $arg);
            if ('-' === $norm || !str_ends_with($norm, '.php') || !is_readable($arg)) {
                continue;
            }
            $candidates[] = dirname($arg);
        }
    }
    $candidates[] = dirname(__DIR__);
    $seen = [];
    foreach ($candidates as $start) {
        if (!is_string($start) || '' === $start || isset($seen[$start])) {
            continue;
        }
        $seen[$start] = true;
        $dir = str_replace('\\', '/', $start);
        for ($depth = 0; $depth < 16; ++$depth) {
            if (is_readable($dir.'/bin/compile.php') && is_readable($dir.'/lib/JIT.php')) {
                $real = realpath($dir);
                $root = false !== $real ? $real : $dir;
                putenv('PHP_COMPILER_REPO_ROOT='.$root);

                return;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }
    }
}

function run(string $filename, string $code, array $options): void
{
    phpc_compile_ensure_repo_root_env();
    $normalized = '-' !== $filename ? str_replace('\\', '/', $filename) : '';
    if ('' !== $normalized
        && '-' !== $filename
        && 'Standard input code' !== $filename
        && 'Command line code' !== $filename
        && !is_file($filename)) {
        fwrite(STDERR, "Could not open file {$filename}\n");
        exit(1);
    }
    if (\class_exists(\PHPCompiler\JIT\Progress::class, false)) {
        \PHPCompiler\JIT\Progress::notePhase('bin_compile_run_begin');
        \PHPCompiler\JIT\Progress::noteEntry($filename);
    }
    // When executing as a compiled native driver, default to self-host mode. Relying on getenv()
    // alone is fragile in early bootstrap contexts, and the native driver is only used for the
    // self-host ladder.
    if (\function_exists('php_compiler_cli_should_skip_entry_driver')) {
        $selfhostAot = getenv('PHP_COMPILER_SELFHOST_AOT');
        if (false === $selfhostAot || '' === $selfhostAot) {
            putenv('PHP_COMPILER_SELFHOST_AOT=1');
        }
        // Compiled argv drivers must enable the M3 compile-driver lowering allowlist; otherwise
        // key Runtime entrypoints can be stubbed and compilation returns null (#3004).
        $m3CompileDriver = getenv('PHP_COMPILER_M3_COMPILE_DRIVER');
        if (false === $m3CompileDriver || '' === $m3CompileDriver) {
            putenv('PHP_COMPILER_M3_COMPILE_DRIVER=1');
        }
        $m3CompileDriverMain = getenv('PHP_COMPILER_M3_COMPILE_DRIVER_MAIN');
        if (false === $m3CompileDriverMain || '' === $m3CompileDriverMain) {
            putenv('PHP_COMPILER_M3_COMPILE_DRIVER_MAIN=1');
        }
        $m4BinCompile = getenv('PHP_COMPILER_M4_BIN_COMPILE_DRIVER');
        if (false === $m4BinCompile || '' === $m4BinCompile) {
            putenv('PHP_COMPILER_M4_BIN_COMPILE_DRIVER=1');
        }
        // Gen-2+ argv driver recompiling bin/compile.php must register bootstrap-aot sidecars (#3004).
        $m5DriverHost = getenv('PHP_COMPILER_M5_DRIVER_HOST');
        if (false === $m5DriverHost || '' === $m5DriverHost) {
            putenv('PHP_COMPILER_M5_DRIVER_HOST=1');
        }
    }
    if ('' !== $normalized && str_contains($normalized, 'bootstrap-aot/')) {
        // Bootstrap AOT fixtures require real JIT lowering; ignore inherited self-host stub env (#1086).
        //
        // But: if we're already running under a compiled self-host driver, forcing stub-off
        // can crash before we have a chance to fall back (e.g. inventory argv driver compiling
        // bootstrap-aot fixtures as part of self-host compile-smoke, #2967).
        //
        // Use a runtime-native marker instead of getenv(): self-host AOT execution stubs and
        // env access can be unreliable precisely in the scenarios we're trying to debug.
        if (!\function_exists('php_compiler_cli_should_skip_entry_driver')) {
            $bootstrapAotLink = getenv('PHP_COMPILER_BOOTSTRAP_AOT_LINK');
            $isBootstrapAotLink = '1' === $bootstrapAotLink || 'true' === strtolower((string) $bootstrapAotLink);
            if ($isBootstrapAotLink) {
                // bootstrap-aot-link: Runtime spine via self-host stubs; user FUNCDEF bodies stay real (#1492).
                putenv('PHP_COMPILER_SELFHOST_AOT=1');
                putenv('PHP_COMPILER_M3_COMPILE_DRIVER=1');
                // Defer nested-JIT standalone bodies during inventory argv emit init (#14470).
                putenv('PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER=1');
                putenv('BOOTSTRAP_M3_USE_INVENTORY_EMIT_DRIVER=1');
            } else {
                putenv('PHP_COMPILER_SELFHOST_AOT=0');
            }
        }
    }
    if ('-' !== $filename && str_contains($normalized, 'test/selfhost/')) {
        $selfhostAot = getenv('PHP_COMPILER_SELFHOST_AOT');
        if (false === $selfhostAot || '' === $selfhostAot) {
            putenv('PHP_COMPILER_SELFHOST_AOT=1');
        }
    }
    if ('' !== $normalized && str_contains($normalized, 'selfhost/') && str_contains($normalized, 'compile_driver.php') && !isset($options['-l'])) {
        putenv('PHP_COMPILER_SELFHOST_AOT=1');
        putenv('PHP_COMPILER_M3_COMPILE_DRIVER=1');
        putenv('PHP_COMPILER_M3_COMPILE_DRIVER_MAIN=1');
        if (str_contains($normalized, 'compiler_helloworld_smoke/compile_driver.php')
            || str_contains($normalized, 'bootstrap_loop_smoke/compile_driver.php')) {
            putenv('PHP_COMPILER_M3_EMIT_LOG_PREFIX=helloworld_compile_smoke');
        } elseif (str_contains($normalized, 'runtime_compile_smoke/compile_driver.php')) {
            putenv('PHP_COMPILER_M3_EMIT_LOG_PREFIX=runtime_compile_smoke_m3_emit');
            putenv('PHP_COMPILER_M3_EMIT_HELPER_SPINE=1');
        } elseif (str_contains($normalized, 'jit_unit_probe/compile_driver.php')) {
            putenv('PHP_COMPILER_M3_EMIT_LOG_PREFIX=jit_unit_probe_m3_emit');
        } elseif (str_contains($normalized, 'compile_driver.php')) {
            putenv('PHP_COMPILER_M3_EMIT_LOG_PREFIX=compile_smoke_m3_emit');
        }
        $inventoryEmit = getenv('BOOTSTRAP_M3_USE_INVENTORY_EMIT_DRIVER') ?: getenv('PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER');
        if ('1' === $inventoryEmit || 'true' === strtolower((string) $inventoryEmit)) {
            putenv('PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER=1');
            // Runtime: do not force emit-helper TU/minimal mode here.
            // The inventory driver is expected to compile arbitrary sources; forcing emit-helper
            // mode can route Runtime::parseAndCompile through minimal sentinel stubs and recurse (#2967).
        }
    }
    if ('' !== $normalized && str_contains($normalized, 'jit_result_stub.php')) {
        putenv('PHP_COMPILER_SELFHOST_AOT=1');
    }
    if ('' !== $normalized && str_contains($normalized, 'bootstrap-vendor-prelink/generated/')) {
        $vendorPrelink = getenv('PHP_COMPILER_VENDOR_PRELINK');
        if ('1' === $vendorPrelink || 'true' === strtolower((string) $vendorPrelink)) {
            // Real-lower parse/compile for vendor bundles; avoid M3 emit-TU sidecar host-compile (#3028, #3036).
            putenv('PHP_COMPILER_M3_EMIT_HELPER_SPINE=1');
            putenv('PHP_COMPILER_KEEP_OBJECT_FILE=1');
        }
    }
    $includes = $options['--include'] ?? [];
    if (!is_array($includes)) {
        $includes = [] === $includes || '' === $includes ? [] : [$includes];
    }
    /** @var list<string> $includes */
    $skipBundle = phpc_compile_skip_aot_bundle($normalized);
    if ([] === $includes && '-' !== $filename && is_file($filename) && !$skipBundle) {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $includes = LiteralIncludeDiscovery::discoverDirectAbsolutePaths($runtime, $filename);
    }
    if ([] !== $includes && !$skipBundle) {
        $projectRoot = DeployRoot::findProjectRootForPath($filename);
        [$code, $filename] = SourceBundler::bundleForAot($filename, $includes, $projectRoot);
    } elseif ('' === $code && '-' !== $filename && is_file($filename)) {
        $code = (string) file_get_contents($filename);
    }

    $runtime = new Runtime(Runtime::MODE_AOT);
    $queryString = $options['-q'] ?? null;
    if (!is_string($queryString) || '' === $queryString) {
        $fromEnv = getenv('QUERY_STRING');
        if (is_string($fromEnv) && '' !== $fromEnv) {
            $queryString = $fromEnv;
        }
    }
    $postBody = $options['-p'] ?? null;
    if (!is_string($postBody) || '' === $postBody) {
        $bodyEnv = getenv('REQUEST_BODY');
        if (is_string($bodyEnv) && '' !== $bodyEnv) {
            $postBody = $bodyEnv;
        }
    }
    $scriptFilename = null;
    if (\function_exists('php_compiler_cli_is_virtual_code_filename')
        ? !php_compiler_cli_is_virtual_code_filename($filename)
        : ('-' !== $filename && 'Standard input code' !== $filename && 'Command line code' !== $filename)) {
        $resolved = realpath($filename);
        if (false !== $resolved) {
            $scriptFilename = $resolved;
        }
    }
    Superglobals::populateFromEnvironment(
        $runtime->vmContext,
        is_string($queryString) ? $queryString : null,
        is_string($postBody) ? $postBody : null,
        $scriptFilename
    );
    $prevUserScriptAot = getenv('PHP_COMPILER_AOT_USER_SCRIPT');
    $setUserScriptAot = phpc_compile_is_user_script_aot($normalized);
    if ($setUserScriptAot && \function_exists('putenv')) {
        putenv('PHP_COMPILER_AOT_USER_SCRIPT=1');
        $_ENV['PHP_COMPILER_AOT_USER_SCRIPT'] = '1';
        $_SERVER['PHP_COMPILER_AOT_USER_SCRIPT'] = '1';
        putenv('PHP_COMPILER_CACHE=0');
        $_ENV['PHP_COMPILER_CACHE'] = '0';
        $_SERVER['PHP_COMPILER_CACHE'] = '0';
    }
    try {
        $block = $runtime->parseAndCompile($code, $filename);
    } finally {
        if ($setUserScriptAot && \function_exists('putenv')) {
            if (false === $prevUserScriptAot || null === $prevUserScriptAot) {
                putenv('PHP_COMPILER_AOT_USER_SCRIPT=');
                unset($_ENV['PHP_COMPILER_AOT_USER_SCRIPT'], $_SERVER['PHP_COMPILER_AOT_USER_SCRIPT']);
            } else {
                putenv('PHP_COMPILER_AOT_USER_SCRIPT='.$prevUserScriptAot);
                $_ENV['PHP_COMPILER_AOT_USER_SCRIPT'] = $prevUserScriptAot;
                $_SERVER['PHP_COMPILER_AOT_USER_SCRIPT'] = $prevUserScriptAot;
            }
        }
    }
    if (null === $block) {
        if (! isset($options['-l'])) {
            $diag = \PHPCompiler\Runtime::getLastParseFailure();
            if (null === $diag || '' === $diag) {
                $diag = $runtime->formatParseAndCompileNullDetail(null);
            }
            $suffix = null !== $diag && '' !== $diag ? ' — '.$diag : '';
            fwrite(STDERR, 'compile.php: parseAndCompile returned null for '.$filename.$suffix."\n");
            exit(2);
        }
        fwrite(STDERR, "phpc lint: failed to compile {$filename}\n");
        exit(1);
    }
    if (! isset($options['-l'])) {
        if (! isset($options['-o']) || $options['-o'] === true) {
            $options['-o'] = str_replace('.php', '', $filename);
        }
        if (isset($options['-y'])) {
            $debugFile = true === $options['-y'] ? $options['-o'] : $options['-y'];
            $runtime->setDebug($debugFile);
        }
        if (isset($options['--debug-symbols'])) {
            $runtime->setAotDebugSymbols(true);
        }
        // Literal path for self-host AOT/JIT include folding (#54, #1492).
        require_once 'lib/AOT/LinkerProcessPolyfill.php';
        if (!\function_exists('phpc_run_command')) {
            /**
             * @param array<string, string>|null $env
             *
             * @return array{code:int,stdout:string,stderr:string}|null
             */
            function phpc_run_command(string $command, ?array $env = null): ?array
            {
                return \PHPCompiler\AOT\LinkerProcessPolyfill::run($command, $env);
            }
        }
        $prevSelfHostAot = getenv('PHP_COMPILER_SELFHOST_AOT');
        $bootstrapAotFixture = '' !== $normalized && str_contains($normalized, 'bootstrap-aot/');
        $bootstrapAotLink = getenv('PHP_COMPILER_BOOTSTRAP_AOT_LINK');
        $isBootstrapAotLink = '1' === $bootstrapAotLink || 'true' === strtolower((string) $bootstrapAotLink);
        // parseAndCompile uses real lowering (SELFHOST_AOT=0) for bootstrap-aot fixtures (#1086); standalone
        // LLVM emit still needs self-host Runtime stubs when the env is explicitly `0` (#1492 bootstrap gate).
        // bootstrap-aot-link sets PHP_COMPILER_BOOTSTRAP_AOT_LINK=1 to keep real lowering for user FUNCDEF bodies.
        $setSelfHostAotForCompile = \function_exists('putenv') && (
            false === $prevSelfHostAot
            || '' === (string) $prevSelfHostAot
            || ($bootstrapAotFixture && '0' === (string) $prevSelfHostAot && !$isBootstrapAotLink)
        );
        if ($setSelfHostAotForCompile && !$setUserScriptAot) {
            // Keep LLVM 9 stable during AOT compilation; some lowering paths are still sensitive (#2600).
            // User-script AOT (examples/*) needs real refresh helpers — skip stubs (#15624).
            putenv('PHP_COMPILER_SELFHOST_AOT=1');
            $_ENV['PHP_COMPILER_SELFHOST_AOT'] = '1';
            $_SERVER['PHP_COMPILER_SELFHOST_AOT'] = '1';
        }
        if ($setUserScriptAot && \function_exists('putenv')) {
            putenv('PHP_COMPILER_AOT_USER_SCRIPT=1');
            $_ENV['PHP_COMPILER_AOT_USER_SCRIPT'] = '1';
            $_SERVER['PHP_COMPILER_AOT_USER_SCRIPT'] = '1';
            // Real CGI refresh helpers (multipart $_FILES) must not use self-host stubs (#15624).
            putenv('PHP_COMPILER_SELFHOST_AOT=0');
            $_ENV['PHP_COMPILER_SELFHOST_AOT'] = '0';
            $_SERVER['PHP_COMPILER_SELFHOST_AOT'] = '0';
            // Stale MCJIT cache can resurrect pre-#13031 superglobal refresh bodies (#15624).
            putenv('PHP_COMPILER_CACHE=0');
            $_ENV['PHP_COMPILER_CACHE'] = '0';
            $_SERVER['PHP_COMPILER_CACHE'] = '0';
        }
        $prevBootstrapAotLink = getenv('PHP_COMPILER_BOOTSTRAP_AOT_LINK');
        $setBootstrapAotLink = $bootstrapAotFixture && !$isBootstrapAotLink && \function_exists('putenv');
        if ($setBootstrapAotLink) {
            putenv('PHP_COMPILER_BOOTSTRAP_AOT_LINK=1');
            $_ENV['PHP_COMPILER_BOOTSTRAP_AOT_LINK'] = '1';
            $_SERVER['PHP_COMPILER_BOOTSTRAP_AOT_LINK'] = '1';
        }
        try {
            $runtime->standalone($block, $options['-o'], $code, $filename);
            \PHPCompiler\AOT\Linker::assertNonEmptyRequestedOutput((string) $options['-o']);
        } catch (\LogicException $e) {
            fwrite(STDERR, $e->getMessage()."\n");
            exit(2);
        } finally {
            if ($setUserScriptAot && \function_exists('putenv')) {
                if (false === $prevUserScriptAot || null === $prevUserScriptAot) {
                    putenv('PHP_COMPILER_AOT_USER_SCRIPT=');
                    unset($_ENV['PHP_COMPILER_AOT_USER_SCRIPT'], $_SERVER['PHP_COMPILER_AOT_USER_SCRIPT']);
                } else {
                    putenv('PHP_COMPILER_AOT_USER_SCRIPT='.$prevUserScriptAot);
                    $_ENV['PHP_COMPILER_AOT_USER_SCRIPT'] = $prevUserScriptAot;
                    $_SERVER['PHP_COMPILER_AOT_USER_SCRIPT'] = $prevUserScriptAot;
                }
            }
            if ($setSelfHostAotForCompile) {
                if (false === $prevSelfHostAot || '' === (string) $prevSelfHostAot) {
                    putenv('PHP_COMPILER_SELFHOST_AOT=');
                    unset($_ENV['PHP_COMPILER_SELFHOST_AOT'], $_SERVER['PHP_COMPILER_SELFHOST_AOT']);
                } else {
                    putenv('PHP_COMPILER_SELFHOST_AOT='.$prevSelfHostAot);
                    $_ENV['PHP_COMPILER_SELFHOST_AOT'] = $prevSelfHostAot;
                    $_SERVER['PHP_COMPILER_SELFHOST_AOT'] = $prevSelfHostAot;
                }
            }
            if ($setBootstrapAotLink) {
                if (false === $prevBootstrapAotLink || null === $prevBootstrapAotLink) {
                    putenv('PHP_COMPILER_BOOTSTRAP_AOT_LINK=');
                    unset($_ENV['PHP_COMPILER_BOOTSTRAP_AOT_LINK'], $_SERVER['PHP_COMPILER_BOOTSTRAP_AOT_LINK']);
                } else {
                    putenv('PHP_COMPILER_BOOTSTRAP_AOT_LINK='.$prevBootstrapAotLink);
                    $_ENV['PHP_COMPILER_BOOTSTRAP_AOT_LINK'] = $prevBootstrapAotLink;
                    $_SERVER['PHP_COMPILER_BOOTSTRAP_AOT_LINK'] = $prevBootstrapAotLink;
                }
            }
        }
    }
}

if (
    !(defined('PHP_COMPILER_LIB_SPINE_SMOKE') && PHP_COMPILER_LIB_SPINE_SMOKE)
    && !(\function_exists('php_compiler_cli_should_skip_entry_driver') && php_compiler_cli_should_skip_entry_driver())
) {
// Use literal require paths so self-host AOT/JIT can fold includes (#54, #1492).
require_once __DIR__.'/../src/cli.php';
php_compiler_cli_note_invocation_cwd();
chdir(__DIR__.'/..');
require_once 'src/cli.php';
require_once 'src/cli_driver.php';
php_compiler_cli_dispatch();
}
