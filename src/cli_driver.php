<?php

declare(strict_types=1);

/** argv driver for bin/*.php entry scripts (issue #1467). */

if (!function_exists('php_compiler_cli_should_skip_entry_driver')) {
    /** Skip argv driver when bundled in compiler_lib_spine_smoke (issue #1467, #1742). */
    function php_compiler_cli_should_skip_entry_driver(): bool
    {
        $flag = getenv('PHP_COMPILER_CLI_SPINE_BUNDLE');
        return '1' === $flag || 'true' === strtolower((string) $flag);
    }
}

if (
    (defined('PHP_COMPILER_LIB_SPINE_SMOKE') && PHP_COMPILER_LIB_SPINE_SMOKE)
    || php_compiler_cli_should_skip_entry_driver()
    || ('1' === getenv('PHP_COMPILER_VM_SPINE_SMOKE') || 'true' === strtolower((string) getenv('PHP_COMPILER_VM_SPINE_SMOKE')))
) {
    return;
}

$autoloadEnv = getenv('PHP_COMPILER_VENDOR_AUTOLOAD');
$skipVendor = getenv('PHP_COMPILER_CLI_SKIP_VENDOR');
// Keep vendor out of literal include discovery for bootstrap AOT/self-host emit paths (issue #2640).
if ('1' !== $skipVendor && 'true' !== strtolower((string) $skipVendor)) {
    // In JIT/AOT, include/require must use a compile-time literal path. If an override is provided,
    // fail fast with a clear message instead of producing non-compilable code.
    if (('1' === getenv('PHP_COMPILER_SELFHOST_AOT') || 'true' === strtolower((string) getenv('PHP_COMPILER_SELFHOST_AOT')))
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
    // Minimal project autoloader for self-host bootstrap paths where composer/vendor is out of scope (issue #2640).
    // Intentionally does not attempt to load vendor namespaces (PHPCfg/PHPTypes/PhpParser/etc).
    if (!function_exists('php_compiler_cli_minimal_autoload')) {
        function php_compiler_cli_minimal_autoload(string $class): void
        {
            $prefix = 'PHPCompiler\\';
            if (!str_starts_with($class, $prefix)) {
                return;
            }
            $rel = substr($class, strlen($prefix));
            $relPath = str_replace('\\', '/', $rel).'.php';

            $libPath = __DIR__.'/../lib/'.$relPath;
            if (is_file($libPath)) {
                /** @psalm-suppress UnresolvableInclude */
                require $libPath;
                return;
            }

            if (str_starts_with($rel, 'Ext\\Standard\\')) {
                $extRel = substr($rel, strlen('Ext\\Standard\\'));
                $extPath = __DIR__.'/../ext/standard/'.str_replace('\\', '/', $extRel).'.php';
                if (is_file($extPath)) {
                    /** @psalm-suppress UnresolvableInclude */
                    require $extPath;
                }
            }
        }
    }
    spl_autoload_register('php_compiler_cli_minimal_autoload');
}

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

$opts = $argv;
// get rid of this
array_shift($opts);

$execFile = '';
$execCode = '';
$options = [];
while (! empty($opts)) {
    $opt = array_shift($opts);
    switch ($opt) {
        case '-l':
            $options['-l'] = true;

            break;
        case '-y':
            if (empty($opts) || substr($opts[0], 0, 1) === '-') {
                $options['-y'] = true;
            } elseif (count($opts) === 1 && substr($opts[0], -4) === '.php') {
                // will assume the same name as the input file...
                $options['-y'] = true;
            } else {
                $options['-y'] = array_shift($opts);
            }

            break;
        case '-o':
            if (empty($opts) || substr($opts[0], 0, 1) === '-') {
                $options['-o'] = true;
            } elseif (count($opts) === 1 && substr($opts[0], -4) === '.php') {
                // will assume the same name as the input file...
                $options['-o'] = true;
            } else {
                $options['-o'] = array_shift($opts);
            }

            break;
        case '-r':
            $execCode = '<?php '.array_shift($opts);
            $execFile = 'Command line code';

            break;
        case '-q':
            if (empty($opts) || substr($opts[0], 0, 1) === '-') {
                die("Option -q requires a query string argument\n");
            }
            $options['-q'] = array_shift($opts);

            break;
        case '-p':
            if (empty($opts) || substr($opts[0], 0, 1) === '-') {
                die("Option -p requires a POST body argument\n");
            }
            $options['-p'] = array_shift($opts);

            break;
        case '--include':
            if (empty($opts) || substr($opts[0], 0, 1) === '-') {
                die("Option --include requires a file path\n");
            }
            $includePath = array_shift($opts);
            if (!is_file($includePath)) {
                die("Could not open include file {$includePath}\n");
            }
            $options['--include'] ??= [];
            $options['--include'][] = realpath($includePath) ?: $includePath;

            break;
        default:
            if (! empty($opts)) {
                die("Extra argument not understood: {$opt}\n");
            }
            if (! empty($execCode)) {
                die("Unsupported argument combination leading to multiple executions\n");
            }
            if (substr($opt, 0, 1) === '-') {
                if (strlen($opt) === 1) {
                    $execFile = '-';
                    $execCode = stream_get_contents(\STDIN);

                    break;
                }
                die("Unsupported bare argument {$opt}\n");
            }
            if (! file_exists($opt)) {
                die("Could not open file {$opt}\n");
            }
            $execCode = file_get_contents($opt);
            $execFile = $opt;
    }
}

if (empty($execCode)) {
    $execFile = '-';
    $execCode = stream_get_contents(\STDIN);
}

if (function_exists('run')) {
    // @phan-suppress-next-line PhanUndeclaredFunction yes it is we just made a function_exists call
    run($execFile, $execCode, $options);
} else {
    throw new \RuntimeException('Must define run before including cli.php');
}
