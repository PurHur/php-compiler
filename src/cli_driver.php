<?php

declare(strict_types=1);

/** argv driver for bin/*.php entry scripts (issue #1467). */

if (!function_exists('php_compiler_cli_should_skip_entry_driver')) {
    /** Skip argv driver when bundled in compiler_lib_spine_smoke (issue #1467, #1742). */
    function php_compiler_cli_should_skip_entry_driver(): bool
    {
        $lc = strtolower((string) getenv('PHP_COMPILER_CLI_SPINE_BUNDLE'));

        return '1' === $lc || 'true' === $lc;
    }
}

if (!function_exists('php_compiler_cli_should_run_entry_driver')) {
    function php_compiler_cli_should_run_entry_driver(): bool
    {
        if (php_compiler_cli_should_skip_entry_driver()) {
            return false;
        }
        $vmSpine = getenv('PHP_COMPILER_VM_SPINE_SMOKE');
        if ('1' === $vmSpine || 'true' === strtolower((string) $vmSpine)) {
            return false;
        }
        // Use getenv (not defined()) so M5 driver AOT is not constant-folded at link time (#1521).
        $libSpine = getenv('PHP_COMPILER_LIB_SPINE_SMOKE');
        if ('1' === $libSpine || 'true' === strtolower((string) $libSpine)) {
            return false;
        }
        if (defined('PHP_COMPILER_LIB_SPINE_SMOKE') && PHP_COMPILER_LIB_SPINE_SMOKE) {
            return false;
        }

        return true;
    }
}

if (!function_exists('php_compiler_cli_dispatch')) {
    function php_compiler_cli_note_progress(string $msg): void
    {
        try {
            if (!class_exists('PHPCompiler\\JIT\\Progress', false)) {
                return;
            }
            \PHPCompiler\JIT\Progress::noteFunction($msg);
        } catch (\Throwable $e) {
            // best-effort only: progress breadcrumbs must not affect CLI behavior
        }
    }

    /**
     * Parse argv and invoke the entry script run() callback.
     *
     * Callable from bin/compile.php {main} under AOT — require side effects do not re-run cli init (#1521).
     */
    function php_compiler_cli_dispatch(): void
    {
        if (!php_compiler_cli_should_run_entry_driver()) {
            return;
        }
        php_compiler_cli_note_progress('php:cli_dispatch_begin');

        $autoloadEnv = getenv('PHP_COMPILER_VENDOR_AUTOLOAD');
        $selfhostAot = getenv('PHP_COMPILER_SELFHOST_AOT');
        $compiledCli = getenv('PHP_COMPILER_CLI_COMPILED');
        $compiledCliLc = strtolower((string) $compiledCli);
        $isCompiledCli = '1' === $compiledCliLc || 'true' === $compiledCliLc;

        // Keep vendor out of literal include discovery for self-host AOT / compiled CLI driver mode (issue #2641, #2640).
        $shouldSkipVendorAutoload = \function_exists('php_compiler_cli_should_skip_vendor_autoload')
            ? php_compiler_cli_should_skip_vendor_autoload()
            : ('1' === $selfhostAot || 'true' === strtolower((string) $selfhostAot));
        if (!$shouldSkipVendorAutoload) {
            // In JIT/AOT, include/require must use a compile-time literal path. If an override is provided,
            // fail fast with a clear message instead of producing non-compilable code.
            if (('1' === $selfhostAot || 'true' === strtolower((string) $selfhostAot))
                && is_string($autoloadEnv) && '' !== $autoloadEnv
            ) {
                fwrite(STDERR, "PHP_COMPILER_VENDOR_AUTOLOAD override is not supported under PHP_COMPILER_SELFHOST_AOT (must be a literal require).\n");
                exit(1);
            }

            $autoloadDefault = __DIR__.'/../vendor/autoload.php';
            if (!is_file($autoloadDefault)) {
                fwrite(STDERR, "Missing vendor autoload at {$autoloadDefault} (did you run composer install?)\n");
                exit(1);
            }
            /** @psalm-suppress UnresolvableInclude */
            require __DIR__.'/../vendor/autoload.php';
        } else {
            if (!function_exists('php_compiler_cli_minimal_autoload')) {
                function php_compiler_cli_minimal_autoload(string $class): void
                {
                    /** @var array<string, string> $prefixMap */
                    $prefixMap = $GLOBALS['__phpc_cli_prefix_map'] ?? null;
                    if (!is_array($prefixMap)) {
                        $prefixMap = [
                            // Extension modules (historical lowercase namespace).
                            'PHPCompiler\\ext\\' => __DIR__.'/../ext/',
                            'PHPCompiler\\' => __DIR__.'/../lib/',
                            'PHPCompiler\\Ext\\Standard\\' => __DIR__.'/../ext/standard/',
                            // Legacy global helper namespace used by VM data structures.
                            'php\\' => __DIR__.'/../php/',
                            // Vendor namespaces required by the compiler parse/type/JIT spine.
                            'PhpParser\\' => __DIR__.'/../vendor/nikic/php-parser/lib/PhpParser/',
                            'PHPCfg\\' => __DIR__.'/../vendor/ircmaxell/php-cfg/lib/PHPCfg/',
                            'PHPTypes\\' => __DIR__.'/../vendor/ircmaxell/php-types/lib/PHPTypes/',
                            'PHPLLVM\\' => __DIR__.'/../vendor/ircmaxell/php-llvm/lib/',
                        ];
                        $GLOBALS['__phpc_cli_prefix_map'] = $prefixMap;
                    }

                    foreach ($prefixMap as $prefix => $baseDir) {
                        if (!str_starts_with($class, $prefix)) {
                            continue;
                        }
                        $rel = substr($class, strlen($prefix));
                        $path = $baseDir.str_replace('\\', '/', $rel).'.php';
                        if (is_file($path)) {
                            /** @psalm-suppress UnresolvableInclude */
                            require $path;
                        }

                        return;
                    }
                }
            }
            // Compiled driver still resolves spine classes at runtime without composer autoload (#1521).
            spl_autoload_register('php_compiler_cli_minimal_autoload');
        }
        php_compiler_cli_note_progress('php:cli_dispatch_after_autoload');

        $memoryLimit = getenv('PHP_COMPILER_MEMORY_LIMIT');
        if (false === $memoryLimit || '' === $memoryLimit) {
            $memoryLimit = '2G';
        }
        if ('-1' === $memoryLimit) {
            fwrite(STDERR, "PHP_COMPILER_MEMORY_LIMIT=-1 is not allowed in this repository (use a finite value, e.g. 2G).\n");
            exit(1);
        }
        ini_set('memory_limit', $memoryLimit);
        // Compliance subprocesses pass -d error_reporting=0; do not re-enable deprecations (#2055).
        if (0 !== (int) ini_get('error_reporting')) {
            error_reporting(E_ALL);
        }

        global $argv;
        if (!isset($argv) || !is_array($argv)) {
            $argv = $GLOBALS['argv'] ?? null;
        }
        if (!is_array($argv)) {
            fwrite(STDERR, "php_compiler_cli_dispatch: missing CLI \$argv\n");
            exit(1);
        }
        php_compiler_cli_note_progress('php:cli_dispatch_have_argv');

        $execFile = '';
        $execCode = '';
        $options = [];
        $scriptArgv = null;
        php_compiler_cli_note_progress('php:cli_dispatch_parse_begin');
        // Avoid array_shift() in the compiled CLI driver: it mutates arrays and can hit
        // bootstrap AOT lowering gaps. Use an index cursor instead (#3004).
        $argc = count($argv);
        $i = 1; // skip argv[0]
        while ($i < $argc) {
            $opt = $argv[$i];
            ++$i;
            switch ($opt) {
                case '-l':
                    $options['-l'] = true;

                    break;
                case '--no-cache':
                    $options['--no-cache'] = true;
                    putenv('PHP_COMPILER_CACHE=0');
                    $_ENV['PHP_COMPILER_CACHE'] = '0';
                    $_SERVER['PHP_COMPILER_CACHE'] = '0';

                    break;
                case '-y':
                    if ($i >= $argc || substr((string) $argv[$i], 0, 1) === '-') {
                        $options['-y'] = true;
                    } elseif ($i === $argc - 1 && substr((string) $argv[$i], -4) === '.php') {
                        // will assume the same name as the input file...
                        $options['-y'] = true;
                    } else {
                        $options['-y'] = $argv[$i];
                        ++$i;
                    }

                    break;
                case '--debug-symbols':
                    $options['--debug-symbols'] = true;

                    break;
                case '-d':
                    if ($i >= $argc) {
                        die("Option -d requires an argument\n");
                    }
                    $iniArg = (string) $argv[$i];
                    ++$i;
                    $eq = strpos($iniArg, '=');
                    if (false === $eq) {
                        die("Invalid -d argument: {$iniArg}\n");
                    }
                    $iniKey = substr($iniArg, 0, $eq);
                    $iniValue = substr($iniArg, $eq + 1);
                    if (!isset($options['-d']) || !is_array($options['-d'])) {
                        $options['-d'] = [];
                    }
                    $options['-d'][$iniKey] = $iniValue;

                    break;
                case '-o':
                    if ($i >= $argc || substr((string) $argv[$i], 0, 1) === '-') {
                        $options['-o'] = true;
                    } elseif ($i === $argc - 1 && substr((string) $argv[$i], -4) === '.php') {
                        // will assume the same name as the input file...
                        $options['-o'] = true;
                    } else {
                        $options['-o'] = $argv[$i];
                        ++$i;
                    }

                    break;
                case '-r':
                    if ($i >= $argc) {
                        die("Option -r requires a code argument\n");
                    }
                    $execCode = '<?php '.$argv[$i];
                    ++$i;
                    $execFile = php_compiler_cli_command_line_code_filename();
                    // Zend CLI: $argv[0] is "Command line code" for -r; args after "--" are user args (not "--" itself).
                    $rest = array_slice($argv, $i);
                    if (isset($rest[0]) && '--' === $rest[0]) {
                        $rest = array_slice($rest, 1);
                    }
                    $scriptArgv = array_merge([$execFile], $rest);
                    // Do not parse trailing args as compiler options; they belong to user code.
                    $i = $argc;

                    break;
                case '-q':
                    if ($i >= $argc || substr((string) $argv[$i], 0, 1) === '-') {
                        die("Option -q requires a query string argument\n");
                    }
                    $options['-q'] = $argv[$i];
                    ++$i;

                    break;
                case '-p':
                    if ($i >= $argc || substr((string) $argv[$i], 0, 1) === '-') {
                        die("Option -p requires a POST body argument\n");
                    }
                    $options['-p'] = $argv[$i];
                    ++$i;

                    break;
                case '--include':
                    if ($i >= $argc || substr((string) $argv[$i], 0, 1) === '-') {
                        die("Option --include requires a file path\n");
                    }
                    $includePath = (string) $argv[$i];
                    ++$i;
                    $includePath = php_compiler_cli_resolve_user_path($includePath);
                    if (!is_file($includePath)) {
                        die("Could not open include file {$includePath}\n");
                    }
                    if (!isset($options['--include']) || !is_array($options['--include'])) {
                        $options['--include'] = [];
                    }
                    $options['--include'][] = realpath($includePath) ?: $includePath;

                    break;
                default:
                    if (! empty($execCode)) {
                        die("Unsupported argument combination leading to multiple executions\n");
                    }
                    if (substr($opt, 0, 1) === '-') {
                        if (strlen($opt) === 1) {
                            $execFile = '-';
                            $execCode = stream_get_contents(\STDIN);
                            // Mirror Zend CLI stdin: $argv[0] is "-" and remaining args follow.
                            $scriptArgv = array_merge([$execFile], array_slice($argv, $i));
                            // Do not parse trailing args as compiler options; they belong to user code.
                            $i = $argc;

                            break;
                        }
                        die("Unsupported bare argument {$opt}\n");
                    }
                    $scriptPath = php_compiler_cli_resolve_user_path($opt);
                    $execCode = file_get_contents($scriptPath);
                    if (false === $execCode) {
                        die("Could not open file {$opt}\n");
                    }
                    if (str_ends_with(strtolower($scriptPath), '.phpt')) {
                        $extracted = php_compiler_cli_extract_phpt_file_section($execCode);
                        if (null !== $extracted) {
                            $execCode = $extracted;
                        }
                    }
                    $execFile = realpath($scriptPath) ?: $scriptPath;
                    // Allow arbitrary user script args after the script path (Zend CLI parity, #4139).
                    $scriptArgv = array_merge([$execFile], array_slice($argv, $i));
                    // Stop parsing: remaining args are for the user script.
                    $i = $argc;
            }
        }
        php_compiler_cli_note_progress('php:cli_dispatch_parse_done');

        if (empty($execCode)) {
            $execFile = '-';
            $execCode = stream_get_contents(\STDIN);
            $scriptArgv = array_merge([$execFile], array_slice($argv, $i));
        }
        if (null === $scriptArgv) {
            // Fallback: ensure user code sees $argv/$argc even if no args were parsed.
            $scriptArgv = [$execFile];
        }
        $options['--script-argv'] = $scriptArgv;

        if (function_exists('run')) {
            php_compiler_cli_note_progress('php:cli_dispatch_run');
            try {
                // @phan-suppress-next-line PhanUndeclaredFunction yes it is we just made a function_exists call
                run($execFile, $execCode, $options);
            } catch (PHPCompiler\VM\ScriptExit $e) {
                exit($e->status);
            } catch (\CompileError $e) {
                // parseAndCompile already logged; mirror Zend compile/parse exit 255 (#5354, #5505).
                exit(255);
            } catch (\LogicException $e) {
                echo $e->getMessage(), "\n";
                exit(255);
            }
        } else {
            throw new \RuntimeException('Must define run before including cli.php');
        }
    }
}

if (!function_exists('php_compiler_cli_extract_phpt_file_section')) {
    /**
     * Extract the `--FILE--` section from a `.phpt` compliance test.
     *
     * Returns null when the input does not look like a `.phpt` payload.
     */
    function php_compiler_cli_extract_phpt_file_section(string $src): ?string
    {
        $pos = strpos($src, "--FILE--");
        if (false === $pos) {
            return null;
        }
        $after = substr($src, $pos);
        if (!is_string($after) || '' === $after) {
            return null;
        }
        $fileStart = strpos($after, "\n");
        if (false === $fileStart) {
            return null;
        }
        $body = substr($after, $fileStart + 1);
        if (!is_string($body)) {
            return null;
        }
        if (preg_match('/\n--[A-Z0-9_]+--\s*\n/s', $body, $m, PREG_OFFSET_CAPTURE)) {
            $end = (int) $m[0][1];

            return substr($body, 0, $end);
        }

        return $body;
    }
}
