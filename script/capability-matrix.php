#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Generate docs/capabilities.md from ext module registrations, opcode handlers, and PHPT coverage.
 *
 * Language constructs (classes, match, …) are in docs/capabilities-syntax.md via capability-syntax.php.
 *
 * Usage:
 *   php script/capability-matrix.php          # write docs/capabilities.md
 *   php script/capability-matrix.php --check  # exit 1 if committed file is stale
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require __DIR__ . '/capability-syntax-lib.php';

/** @return array<string, array{vm: bool, jit: bool, aot: bool, notes: list<string>, module: string}> */
function collectCapabilities(string $root): array
{
    $modules = [
        'types' => new PHPCompiler\ext\types\Module(),
        'bcmath' => new PHPCompiler\ext\bcmath\Module(),
        'gmp' => new PHPCompiler\ext\gmp\Module(),
        'bz2' => new PHPCompiler\ext\bz2\Module(),
        'stats' => new PHPCompiler\ext\stats\Module(),
        'opcache' => new PHPCompiler\ext\opcache\Module(),
        'ctype' => new PHPCompiler\ext\ctype\Module(),
        'tokenizer' => new PHPCompiler\ext\tokenizer\Module(),
        'filter' => new PHPCompiler\ext\filter\Module(),
        'fileinfo' => new PHPCompiler\ext\fileinfo\Module(),
        'iconv' => new PHPCompiler\ext\iconv\Module(),
        'session' => new PHPCompiler\ext\session\Module(),
        'mbstring' => new PHPCompiler\ext\mbstring\Module(),
        'intl' => new PHPCompiler\ext\intl\Module(),
        'standard' => new PHPCompiler\ext\standard\Module(),
        'openssl' => new PHPCompiler\ext\openssl\Module(),
        'sodium' => new PHPCompiler\ext\sodium\Module(),
        'sockets' => new PHPCompiler\ext\sockets\Module(),
        'zmq' => new PHPCompiler\ext\zmq\Module(),
        'curl' => new PHPCompiler\ext\curl\Module(),
        'zip' => new PHPCompiler\ext\zip\Module(),
        'rar' => new PHPCompiler\ext\rar\Module(),
        'imap' => new PHPCompiler\ext\imap\Module(),
        'eio' => new PHPCompiler\ext\eio\Module(),
        'ssh2' => new PHPCompiler\ext\ssh2\Module(),
        'inotify' => new PHPCompiler\ext\inotify\Module(),
        'uuid' => new PHPCompiler\ext\uuid\Module(),
        'enchant' => new PHPCompiler\ext\enchant\Module(),
        'gnupg' => new PHPCompiler\ext\gnupg\Module(),
        'pspell' => new PHPCompiler\ext\pspell\Module(),
        'odbc' => new PHPCompiler\ext\odbc\Module(),
        'dba' => new PHPCompiler\ext\dba\Module(),
        'mailparse' => new PHPCompiler\ext\mailparse\Module(),
        'redis' => new PHPCompiler\ext\redis\Module(),
        'memcached' => new PHPCompiler\ext\memcached\Module(),
        'mongodb' => new PHPCompiler\ext\mongodb\Module(),
        'snmp' => new PHPCompiler\ext\snmp\Module(),
        'uploadprogress' => new PHPCompiler\ext\uploadprogress\Module(),
        'apcu' => new PHPCompiler\ext\apcu\Module(),
        'sysvshm' => new PHPCompiler\ext\sysvshm\Module(),
        'sysvsem' => new PHPCompiler\ext\sysvsem\Module(),
        'sysvmsg' => new PHPCompiler\ext\sysvmsg\Module(),
    ];

    $capabilities = [];

    foreach ($modules as $moduleLabel => $module) {
        foreach ($module->getFunctions() as $fn) {
            if (!$fn instanceof PHPCompiler\Func\Internal) {
                continue;
            }
            $name = $fn->getName();
            $analysis = analyzeInternal($fn);
            $capabilities[$name] = [
                'vm' => true,
                'jit' => $analysis['jit'],
                'aot' => $analysis['jit'],
                'notes' => $analysis['notes'],
                'module' => $moduleLabel,
            ];
        }
    }

    ksort($capabilities, SORT_STRING);

    return $capabilities;
}

/**
 * @return array{jit: bool, notes: list<string>}
 */
function analyzeInternal(PHPCompiler\Func\Internal $fn): array
{
    $ref = new ReflectionClass($fn);
    $file = $ref->getFileName();
    $source = $file !== false ? (string) file_get_contents($file) : '';
    $notes = [];

    if (preg_match('/\bVM only\b/i', $source)) {
        $notes[] = 'doc: VM only';
    }
    if ('gethostbynamel' === $fn->getName() && preg_match('/native getaddrinfo/i', $source)) {
        $notes[] = 'native getaddrinfo (VM FFI + AOT) (#4928)';
    }
    if ('gethostbyname' === $fn->getName() && preg_match('/JitGethostbyname/i', $source)) {
        $notes[] = 'forward DNS IPv4 (VM FFI + AOT delegate) (#7419)';
    }
    if ('gethostbyaddr' === $fn->getName() && preg_match('/GethostbyaddrRuntime/i', $source)) {
        $notes[] = 'reverse DNS IPv4 (VM FFI + AOT) (#5854)';
    }
    if (in_array($fn->getName(), ['checkdnsrr', 'dns_check_record'], true)
        && preg_match('/CheckdnsrrRuntime|JitCheckdnsrr/i', $source)) {
        $notes[] = 'DNS record probe via libc res_query (VM FFI + JIT/AOT) (#5983)';
    }
    if (in_array($fn->getName(), ['dns_get_mx', 'getmxrr'], true)
        && preg_match('/JitDnsGetMx|dnsGetMxViaUdp/i', $source)) {
        $notes[] = 'MX lookup via UDP DNS + compile-time JIT materializer (VM + JIT/AOT literals) (#4125, #3662)';
    }
    if (in_array($fn->getName(), ['long2ip', 'ip2long', 'inet_ntop', 'inet_pton'], true)
        && preg_match('/JitInet|InetRuntime|VmInetNative/i', $source)) {
        $notes[] = 'IPv4/IPv6 conversion (VM libc FFI + JIT/AOT libc) (#3225)';
    }
    if ('mime_content_type' === $fn->getName() && preg_match('/MimeContentTypeRuntime/i', $source)) {
        $notes[] = 'file MIME sniff (VM host fileinfo + AOT byte sniff) (#6196)';
    }
    if (str_starts_with($fn->getName(), 'finfo_') && preg_match('/VmFinfo|VmMime|JitFinfoFile|FinfoFileRuntime/i', $source)) {
        if (preg_match('/JitFinfoFile|FinfoFileRuntime/i', $source)) {
            $notes[] = 'ext/fileinfo MIME sniff via VmMime + FinfoFileRuntime AOT (#3366,#27196; FILEINFO_NONE/RAW still VM-rich)';
        } else {
            $notes[] = 'ext/fileinfo VM sniff via VmMime + FILEINFO_NONE/RAW human desc (#3366,#19247; JIT deferred)';
        }
    }
    if ('openssl_cipher_key_length' === $fn->getName()
        && preg_match('/JitOpensslCipherKeyLength/i', $source)) {
        $notes[] = 'cipher key length table (VM OpensslCipherRegistry + JIT/AOT literals) (#6522)';
    }
    if ('openssl_cipher_iv_length' === $fn->getName()
        && preg_match('/JitOpensslCipherIvLength/i', $source)) {
        $notes[] = 'cipher IV length table (VM OpensslCipherRegistry + JIT/AOT literals) (#7331)';
    }
    if ('get_meta_tags' === $fn->getName() && preg_match('/JitGetMetaTags|MetaTagsRuntime/i', $source)) {
        $notes[] = 'HTML meta name/content (VM + JIT/AOT MetaTagsRuntime) (#3703, #4608)';
    }
    if (in_array($fn->getName(), ['strcspn', 'strspn'], true) && preg_match('/GH-12592/i', $source)) {
        $notes[] = 'empty $characters: PHP 8.4 full byte length (GH-12592, #7088)';
    }
    if ('array_map' === $fn->getName() && str_contains($source, 'VmClosureCall::isClosure')) {
        $notes[] = 'callbacks: null/string builtins JIT/AOT; VM closure callbacks (#3086, #1154)';
    } elseif ('array_map' === $fn->getName() && preg_match('/callables are deferred/i', $source)) {
        $notes[] = 'callbacks: null/string builtins; closures deferred (#1154)';
    }
    if ('usort' === $fn->getName() && str_contains($source, 'sortPackedWithClosure')) {
        $notes[] = 'callbacks: strcmp + closure JIT/AOT; strcasecmp VM (#3086, #3597)';
    } elseif ('usort' === $fn->getName() && str_contains($source, 'VmClosureCall::isClosure')) {
        $notes[] = 'callbacks: strcmp JIT; strcasecmp VM; VM closure comparator (#3086, #1210)';
    } elseif ('usort' === $fn->getName() && preg_match('/callables are deferred/i', $source)) {
        $notes[] = 'callbacks: strcmp JIT; strcasecmp VM; closures deferred (#1210)';
    }
    if ('uksort' === $fn->getName() && str_contains($source, 'sortStringKeysWithClosure')) {
        $notes[] = 'callbacks: strcmp + closure JIT/AOT; strcasecmp VM (#3086, #3597)';
    } elseif ('uksort' === $fn->getName() && str_contains($source, 'VmClosureCall::isClosure')) {
        $notes[] = 'callbacks: strcmp JIT (ksort lowering); strcasecmp VM; VM closure comparator (#3086, #3143)';
    } elseif ('uksort' === $fn->getName() && preg_match('/callables are deferred/i', $source)) {
        $notes[] = 'callbacks: strcmp JIT; strcasecmp VM; closures deferred (#3143)';
    }
    if ('uasort' === $fn->getName() && str_contains($source, 'VmClosureCall::isClosure')) {
        $notes[] = 'callbacks: strcmp JIT; strcasecmp VM; VM closure comparator (#3086, #3582)';
    } elseif ('uasort' === $fn->getName() && preg_match('/callables are deferred/i', $source)) {
        $notes[] = 'callbacks: strcmp JIT; strcasecmp VM; closures deferred (#1211)';
    }
    if ('array_filter' === $fn->getName() && str_contains($source, 'VmClosureCall::isClosure')) {
        $notes[] = 'callbacks: string builtins; VM closure callbacks (#3086)';
    }
    if (in_array($fn->getName(), ['array_find', 'array_find_key', 'array_any', 'array_all'], true)
        && str_contains($source, 'ArrayFindHelper')) {
        $notes[] = 'callbacks: string builtins/user functions/closure JIT/AOT (#3073); VM closure callbacks (#3086)';
    } elseif (in_array($fn->getName(), ['array_find', 'array_find_key', 'array_any', 'array_all'], true)
        && str_contains($source, 'VmArrayValueCallback')) {
        $notes[] = 'callbacks: string builtins/user functions; VM closure callbacks (#3073)';
    }
    if ('array_walk_recursive' === $fn->getName() && str_contains($source, 'VmClosureCall::isClosure')) {
        $notes[] = 'callbacks: string builtins JIT/AOT (#3111); VM closure callbacks (#3086)';
    }
    if ('array_walk' === $fn->getName() && str_contains($source, 'VmClosureCall::isClosure')) {
        $notes[] = 'callbacks: string builtins JIT/AOT; VM closure + optional userdata (#3627)';
    }
    if ('array_reduce' === $fn->getName() && str_contains($source, 'ArrayReduceRuntime::reduce')) {
        $notes[] = 'callbacks: string user functions + closure/arrow JIT/AOT (#3531); php-src-strict invalid callback TypeError (#6679)';
    } elseif ('array_reduce' === $fn->getName() && str_contains($source, 'VmClosureCall::isClosure')) {
        $notes[] = 'callbacks: string user functions + VM closures; php-src-strict invalid callback TypeError (#6679)';
    } elseif ('array_reduce' === $fn->getName() && preg_match('/callables are deferred/i', $source)) {
        $notes[] = 'callbacks: string user functions VM-only; closures deferred (#1213, #142)';
    }
    if ('preg_replace_callback' === $fn->getName() && str_contains($source, 'VmCallableInvoke::invokeOne')) {
        $notes[] = 'VM any callable; JIT/AOT compile-time string user-function names (#1177, #4442, #142)';
    } elseif ('preg_replace_callback' === $fn->getName() && preg_match('/closures deferred/i', $source)) {
        $notes[] = 'compile-time string user-function callbacks; closures deferred (#1177, #142)';
    }
    if ('preg_replace_callback_array' === $fn->getName() && str_contains($source, 'VmPregReplaceCallbackArray::invoke')) {
        $notes[] = 'VM any callable; JIT/AOT via PregReplaceCallbackArrayRuntime + PregJitHelper (#3568)';
    }
    if (\PHPCompiler\JIT\SelfHostBuiltinPolicy::isVmOnlyDeferred($fn->getName())) {
        $deferLib = __DIR__.'/stdlib-jit-deferred-lib.php';
        if (is_readable($deferLib)) {
            require_once $deferLib;
            $issue = stdlib_jit_deferred_issue_for($fn->getName());
            if (null !== $issue) {
                $notes[] = 'compile-time JIT deferred (#'.$issue.')';
            }
        }
    }

    $jit = false;
    if ($ref->hasMethod('call')) {
        $call = $ref->getMethod('call');
        $declaring = $call->getDeclaringClass();
        $bodyFile = $declaring->getFileName();
        if (false !== $bodyFile) {
            $body = extractMethodBody($bodyFile, $call);
            $jit = !preg_match('/not implemented for JIT/i', $body);
            if (!$jit && preg_match('/not implemented for JIT[^\'"]*([^\']+)/i', $body, $m)) {
                $notes[] = trim($m[0]);
            }
        }
    }

    return ['jit' => $jit, 'notes' => $notes];
}

function extractMethodBody(string $file, ReflectionMethod $method): string
{
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return '';
    }
    $start = $method->getStartLine() - 1;
    $end = $method->getEndLine() - 1;

    return implode("\n", array_slice($lines, $start, $end - $start + 1));
}

/** @return array<string, list<string>> */
function collectPhptCoverage(string $root): array
{
    $jit = [];
    $aot = [];

    $jitDirs = [
        $root . '/test/compliance/cases',
    ];
    foreach ($jitDirs as $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
        foreach ($it as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.phpt')) {
                continue;
            }
            if (!str_contains($file->getFilename(), 'jit')) {
                continue;
            }
            tagPhptFunctions($file->getPathname(), $jit, 'JIT PHPT');
        }
    }

    $aotDir = $root . '/test/fixtures/aot/cases';
    if (is_dir($aotDir)) {
        foreach (glob($aotDir . '/*.phpt') ?: [] as $phpt) {
            tagPhptFunctions($phpt, $aot, 'AOT PHPT');
        }
    }

    return ['jit' => $jit, 'aot' => $aot];
}

/** @param array<string, list<string>> $bucket */
function tagPhptFunctions(string $phpt, array &$bucket, string $tag): void
{
    $content = (string) file_get_contents($phpt);
    if (preg_match('/--TEST--\s*\n(.+)/', $content, $m)) {
        $title = strtolower(trim($m[1]));
        if (preg_match('/\b([a-z_][a-z0-9_]*)\(\)/', $title, $fn)) {
            $bucket[$fn[1]] ??= [];
            if (!in_array($tag, $bucket[$fn[1]], true)) {
                $bucket[$fn[1]][] = $tag;
            }
        }
    }
    if (preg_match('/--FILE--\s*\n<\?php(.*?)(?:--EXPECT|$)/s', $content, $m)) {
        if (preg_match_all('/\b([a-z_][a-z0-9_]*)\s*\(/', $m[1], $calls)) {
            foreach (array_unique($calls[1]) as $fn) {
                if (in_array($fn, ['echo', 'print', 'var_dump', 'putenv', 'define'], true)) {
                    continue;
                }
                $bucket[$fn] ??= [];
                if (!in_array($tag, $bucket[$fn], true)) {
                    $bucket[$fn][] = $tag;
                }
            }
        }
    }
}

/**
 * @param array<string, array{vm: bool, jit: bool, aot: bool|string, notes: list<string>, module: string}> $capabilities
 */
function renderBuiltinMarkdown(array $capabilities, array $phpt): string
{
    $lines = [
        '# Capability matrix',
        '',
        'Auto-generated by `script/capability-matrix.php`. Do not edit by hand.',
        '',
        '## Builtin functions',
        '',
        '| Function | VM | JIT | AOT | Module | Notes |',
        '|----------|:--:|:---:|:---:|--------|-------|',
    ];

    foreach ($capabilities as $name => $row) {
        $notes = $row['notes'];
        foreach ($phpt['jit'][$name] ?? [] as $tag) {
            $notes[] = $tag;
        }
        foreach ($phpt['aot'][$name] ?? [] as $tag) {
            $notes[] = $tag;
        }
        $notes = array_values(array_unique($notes));
        $lines[] = sprintf(
            '| `%s` | %s | %s | %s | %s | %s |',
            $name,
            capabilityCell($row['vm']),
            capabilityCell($row['jit']),
            capabilityCell($row['aot']),
            $row['module'],
            $notes === [] ? '' : implode('; ', $notes)
        );
    }

    $lines[] = '';
    $lines[] = '## Language constructs';
    $lines[] = '';
    $lines[] = 'See [capabilities-syntax.md](capabilities-syntax.md) (generated by `script/capability-syntax.php`):';
    $lines[] = 'classes, methods, visibility, `instanceof`, native user-class link (#568 closed; execute ✅ #764 closed), `match`, arrow functions.';
    $lines[] = '';
    $lines[] = '_Builtin AOT uses the same LLVM path as JIT unless noted otherwise._';
    $lines[] = '';

    return implode("\n", $lines);
}

/** @param list<string> $argv */
function runCapabilityMatrixCli(array $argv): int
{
    global $root;

    $check = in_array('--check', $argv, true);
    $outFile = $root.'/docs/capabilities.md';

    $capabilities = applyBuiltinCapabilityCurations(
        applyBuiltinAdvertisementParity(collectCapabilities($root), $root)
    );
    $phpt = collectPhptCoverage($root);
    $markdown = renderBuiltinMarkdown($capabilities, $phpt);

    if ($check) {
        if (!is_file($outFile)) {
            fwrite(STDERR, "Missing $outFile — run: php script/capability-matrix.php\n");

            return 1;
        }
        $committed = (string) file_get_contents($outFile);
        if ($committed !== $markdown) {
            fwrite(STDERR, "docs/capabilities.md is out of date — run: php script/capability-matrix.php\n");

            return 1;
        }
        fwrite(STDOUT, 'docs/capabilities.md is up to date ('.count($capabilities)." builtins).\n");

        return 0;
    }

    if (!is_dir(dirname($outFile))) {
        mkdir(dirname($outFile), 0755, true);
    }
    file_put_contents($outFile, $markdown);
    fwrite(STDOUT, "Wrote $outFile (".count($capabilities)." builtins).\n");

    return 0;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    exit(runCapabilityMatrixCli($argv));
}
