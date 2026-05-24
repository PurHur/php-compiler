#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Compute M2 spine gap batches for GitHub issue creation (#1056).
 * Usage: php script/create-m2-spine-batch-issues.php [--json]
 */

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
require __DIR__.'/bootstrap-lib.php';

$jsonOut = in_array('--json', $argv, true);
$spineMain = $root.'/test/selfhost/compiler_lib_spine_smoke/main.php';
$report = bootstrapCollectInventoryReport($root);
$inventoryFiles = array_keys($report['files']);

preg_match_all('#(?:lib|ext|bin)/[^"\'\s]+#', (string) file_get_contents($spineMain), $m);
$inSpine = array_flip($m[0]);
$missing = array_values(array_filter($inventoryFiles, fn (string $f): bool => !isset($inSpine[$f])));

/** @return list<string> */
function filterPrefix(array $files, string $prefix): array
{
    $out = array_values(array_filter($files, fn (string $f): bool => str_starts_with($f, $prefix)));
    sort($out);

    return $out;
}

/** @param list<string> $files */
function fileListMd(array $files): string
{
    if ([] === $files) {
        return "_None._\n";
    }
    $lines = [];
    foreach ($files as $f) {
        $lines[] = '- `'.$f.'`';
    }

    return implode("\n", $lines)."\n";
}

$jitMissing = filterPrefix($missing, 'ext/standard/Jit');
$libJitMissing = filterPrefix($missing, 'lib/JIT/');
$libWebMissing = filterPrefix($missing, 'lib/Web/');
$libCliMissing = filterPrefix($missing, 'lib/Cli/');
$libAotMissing = filterPrefix($missing, 'lib/AOT/');
$extTypesMissing = filterPrefix($missing, 'ext/types/');
$binMissing = filterPrefix($missing, 'bin/');

$jitFs = array_values(array_filter($jitMissing, fn (string $f): bool => preg_match(
    '/Jit(File|Fwrite|Fflush|Ftell|FsGlob|Stat|Mkdir|Rmdir|Unlink|Rename|Touch|Readfile|Readlink|Path|Pathinfo|ShellExec|SysGetTempDir|RequestBody|WebParams)/',
    basename($f)
) === 1));
$jitStr = array_values(array_filter($jitMissing, fn (string $f): bool => preg_match(
    '/Jit(Str|Sprintf|StripTags|Stripslashes|StrPad|StrRepeat|StrShuffle|StrSplit|Strpbrk|Strpos|Strrchr|Strrpos|Strstr|SubstrCount|StringConcat|StringIndex|Wordwrap|Hex2bin|NumberFormat)/',
    basename($f)
) === 1));
$jitHttpPreg = array_values(array_filter($jitMissing, fn (string $f): bool => preg_match(
    '/Jit(Header|Setcookie|Getallheaders|PendingHeaders|HttpResponseCode|HttpBuildQuery|Preg|ParseUrl|ParseStr)/',
    basename($f)
) === 1));
$jitRest = array_values(array_diff($jitMissing, $jitFs, $jitStr, $jitHttpPreg));

$extStdMissing = filterPrefix($missing, 'ext/standard/');
$wrappers = array_values(array_filter($extStdMissing, fn (string $f): bool => !str_starts_with(basename($f), 'Jit') && !str_starts_with(basename($f), 'Vm')));
$vmLeaves = array_values(array_filter($extStdMissing, fn (string $f): bool => str_starts_with(basename($f), 'Vm')));

/** @param list<string> $files */
function wrapperDomain(string $f): string
{
    $b = basename($f, '.php');
    $b = ltrim($b, '_');
    if (preg_match('/^(file_|is_file|is_dir|is_readable|is_writable|fopen|fread|fwrite|fclose|feof|fseek|ftell|fflush|fpassthru|fget|fput|realpath|dirname|basename|pathinfo|glob|scandir|mkdir|rmdir|unlink|copy|rename|touch|chmod|stat|clearstatcache|readlink|filetype|filesize|filemtime|fileperms|tempnam|sys_get_temp_dir|chdir|getcwd|readfile|file_get_contents|file_put_contents|deploy_path|stream_)/', $b)) {
        return 'filesystem-stream';
    }
    if (preg_match('/^(str|substr|strpos|strstr|strr|strcmp|strncmp|strcasecmp|strncasecmp|strlen|trim|ltrim|rtrim|explode|implode|chunk_split|wordwrap|nl2br|htmlspecialchars|strip_tags|addslashes|stripslashes|quotemeta|strtr|str_pad|str_repeat|str_shuffle|str_split|strpbrk|str_rot13|urlencode|urldecode|rawurlencode|rawurldecode|bin2hex|hex2bin|base64|sprintf|number_format|ucwords|lcfirst|ucfirst|strtolower|strtoupper)/', $b)) {
        return 'string-format';
    }
    if (preg_match('/^(array_|in_array|array_key|array_column|array_map|array_filter|array_reduce|array_walk|array_merge|array_slice|array_splice|array_shift|array_unshift|array_pop|array_push|array_values|array_keys|array_combine|array_flip|array_unique|array_reverse|array_search|count|sizeof)/', $b)) {
        return 'array';
    }
    if (preg_match('/^(preg_)/', $b)) {
        return 'preg';
    }
    if (preg_match('/^(json_)/', $b)) {
        return 'json';
    }
    if (preg_match('/^(hash|hash_|crc32|md5|sha1)/', $b)) {
        return 'hash-crypto';
    }
    if (preg_match('/^(header|http_|setcookie|setrawcookie|getallheaders)/', $b)) {
        return 'http-headers';
    }
    if (preg_match('/^(session_)/', $b)) {
        return 'session';
    }
    if (preg_match('/^(filter_)/', $b)) {
        return 'filter';
    }
    if (preg_match('/^(parse_url|parse_str|http_build_query)/', $b)) {
        return 'url-parse';
    }
    if (preg_match('/^(serialize|unserialize)/', $b)) {
        return 'serialize';
    }
    if (preg_match('/^(class_exists|interface_exists|trait_exists|enum_exists|function_exists|property_exists|get_object_vars|method_exists)/', $b)) {
        return 'reflection-exists';
    }
    if (preg_match('/^(getenv|putenv|ini_|sleep|usleep|pack|shell_exec|random_bytes|date|time|microtime)/', $b)) {
        return 'runtime-env';
    }

    return 'misc';
}

$wrapperGroups = [];
foreach ($wrappers as $f) {
    $wrapperGroups[wrapperDomain($f)][] = $f;
}
foreach ($wrapperGroups as &$wg) {
    sort($wg);
}
unset($wg);
ksort($wrapperGroups);

$otherLib = array_values(array_filter($missing, fn (string $f): bool => str_starts_with($f, 'lib/')
    && !str_starts_with($f, 'lib/JIT/')
    && !str_starts_with($f, 'lib/Web/')
    && !str_starts_with($f, 'lib/Cli/')
    && !str_starts_with($f, 'lib/AOT/')));
sort($otherLib);

$srcMissing = filterPrefix($missing, 'src/');

/** @var list<string> */
const M2_EXCLUDED_FROM_SPINE = [
    'lib/AOT/Linker.php', // shell_exec — external clang only (#1056)
];

$gateBlock = <<<'MD'

## Gates (each PR)

```bash
script/apply-patches.sh
php bin/compile.php -l test/selfhost/compiler_lib_spine_smoke/main.php
BOOTSTRAP_LIB_SPINE_SMOKE=1 make bootstrap-selfhost-lib-spine-smoke
make bootstrap-aot-link
vendor/bin/phpunit test/unit/BootstrapSelfhostBundleTest.php
```

## How to add units

1. `require_once` each file in `test/selfhost/compiler_lib_spine_smoke/main.php`
2. Confirm `SelfHostBuiltinPolicy` if stdlib JIT leaf
3. `php script/bootstrap-selfhost-next-includes.php --bundle=test/selfhost/compiler_lib_spine_smoke/main.php`
4. Update M2 count in `docs/pages/development-status.md`

MD;

$batches = [
    [
        'slug' => 'lib-jit-helpers',
        'title' => 'M2 spine: lib/JIT helpers and callback policies',
        'files' => $libJitMissing,
        'notes' => 'JIT compile-time helpers pulled in by Compiler/stdlib lowering. Add before dependent ext/standard Jit* batches.',
    ],
    [
        'slug' => 'lib-web-project',
        'title' => 'M2 spine: lib/Web project deploy + devserver path',
        'files' => $libWebMissing,
        'notes' => 'Project manifest, autoload, bootstrap — needed for `phpc build --project` on vm.php path.',
    ],
    [
        'slug' => 'lib-cli-vm-entry',
        'title' => 'M2 spine: lib/Cli/PhpcRun + bin/vm.php inventory',
        'files' => array_merge($libCliMissing, $binMissing),
        'notes' => 'CLI run spine toward real `bin/vm.php` (stub policy for Doctor/Cgi until M5).',
    ],
    [
        'slug' => 'lib-aot-projectgraph',
        'title' => 'M2 spine: lib/AOT/ProjectGraph (Linker excluded)',
        'files' => array_values(array_filter($libAotMissing, fn (string $f): bool => 'lib/AOT/Linker.php' !== $f)),
        'notes' => '`lib/AOT/Linker.php` stays **excluded** (shell_exec); document in PR. ProjectGraph only.',
    ],
    [
        'slug' => 'lib-other-top',
        'title' => 'M2 spine: remaining lib/ top-level + Lint + VM gaps',
        'files' => $otherLib,
        'notes' => 'Non-JIT lib units on inventory not yet in spine smoke bundle.',
    ],
    [
        'slug' => 'ext-types',
        'title' => 'M2 spine: ext/types modules on vm.php path',
        'files' => $extTypesMissing,
        'notes' => 'Type extension modules referenced from compiler inventory.',
    ],
    [
        'slug' => 'jit-filesystem-stream',
        'title' => 'M2 spine: ext/standard Jit* filesystem + stream + stat',
        'files' => $jitFs,
        'notes' => 'Add Jit* leaf units; audit `SelfHostBuiltinPolicy::isRequiredForBundle`.',
    ],
    [
        'slug' => 'jit-string-format',
        'title' => 'M2 spine: ext/standard Jit* string + format',
        'files' => $jitStr,
        'notes' => 'String/search/replace Jit modules missing from spine.',
    ],
    [
        'slug' => 'jit-http-preg',
        'title' => 'M2 spine: ext/standard Jit* HTTP headers + preg + URL parse',
        'files' => $jitHttpPreg,
        'notes' => 'Web north-star builtins; pair with lib/JIT/Builtin String* where duplicated.',
    ],
    [
        'slug' => 'jit-reflection-runtime',
        'title' => 'M2 spine: ext/standard Jit* reflection + runtime misc',
        'files' => $jitRest,
        'notes' => 'Remaining Jit* leaves (exists*, pack, sleep, get_object_vars, etc.).',
    ],
    [
        'slug' => 'vm-leaves',
        'title' => 'M2 inventory: ext/standard Vm* handler leaves (bundle optional)',
        'files' => $vmLeaves,
        'notes' => 'VM handlers — usually pulled via Module.php; add to spine only if AOT lint requires explicit closure.',
    ],
    [
        'slug' => 'src-cli-spine',
        'title' => 'M2 spine: src/ CLI bootstrap (cli.php + compat shims)',
        'files' => $srcMissing,
        'notes' => 'Entry shims used by `bin/compile.php` / `bin/vm.php`. May stay host-only until M4; track for inventory.',
    ],
];

foreach ($wrapperGroups as $domain => $files) {
    $chunks = array_chunk($files, 35);
    foreach ($chunks as $i => $chunk) {
        $suffix = count($chunks) > 1 ? ' (part '.($i + 1).'/'.count($chunks).')' : '';
        $batches[] = [
            'slug' => 'wrapper-'.$domain.($i > 0 ? '-'.($i + 1) : ''),
            'title' => 'M2 inventory: ext/standard '.$domain.' wrappers'.$suffix,
            'files' => $chunk,
            'notes' => 'Module registration / inventory closure. Lower priority than Jit* spine adds unless lint fails without them.',
        ];
    }
}

$umbrellaBody = <<<'MD'
## Summary

Umbrella tracker for **M2 spine growth** — bringing `test/selfhost/compiler_lib_spine_smoke/main.php` toward the full `bin/vm.php` inventory ([#1056](https://github.com/PurHur/php-compiler/issues/1056)).

**Current:** ~179 units in spine · **Inventory:** ~567 files · **Gap:** ~388 files not yet in spine bundle.

## Batch groups

| Group | Focus |
|-------|--------|
| **A — lib/** | JIT helpers, Web project, Cli/vm.php, AOT ProjectGraph, Lint/top |
| **B — Jit*** | Missing `ext/standard/Jit*.php` leaf modules (66) |
| **C — wrappers** | `ext/standard/*.php` Module inventory (258+) |
| **D — Vm*** | VM handler leaves (27) |
| **E — src/** | CLI bootstrap shims (5) |

**Excluded (intentional):** `lib/AOT/Linker.php` — external `clang` only.

Child issues are linked below as they are created.

## Gates

See each child issue; common ladder in `docs/bootstrap-selfhost.md`.

## References

- [`docs/bootstrap-m5-fast-path.md`](https://github.com/PurHur/php-compiler/blob/master/docs/bootstrap-m5-fast-path.md)
- [`docs/bootstrap-inventory.md`](https://github.com/PurHur/php-compiler/blob/master/docs/bootstrap-inventory.md)
- `php script/bootstrap-selfhost-next-includes.php --bundle=test/selfhost/compiler_lib_spine_smoke/main.php`

MD;

$out = [
    'generated_at' => gmdate('c'),
    'inventory_total' => count($inventoryFiles),
    'spine_total' => count($inSpine),
    'missing_total' => count($missing),
    'umbrella' => [
        'title' => 'M2 spine growth: batch tracker (compiler_lib_spine_smoke → full inventory)',
        'body' => $umbrellaBody,
    ],
    'batches' => [],
];

foreach ($batches as $batch) {
    if ([] === $batch['files']) {
        continue;
    }
    $count = count($batch['files']);
    $body = "## Summary\n\n{$batch['notes']}\n\n";
    $body .= "**Units in this batch:** {$count}\n\n";
    $body .= "## Files to add to spine / inventory\n\n";
    $body .= fileListMd($batch['files']);
    $body .= $gateBlock;
    $body .= "\n**Parent:** #1056 · **Umbrella:** (linked after creation)\n";
    $out['batches'][] = [
        'slug' => $batch['slug'],
        'title' => $batch['title'].' ('.$count.' files)',
        'body' => $body,
        'file_count' => $count,
        'files' => $batch['files'],
    ];
}

$covered = [];
foreach ($out['batches'] as $b) {
    foreach ($b['files'] as $f) {
        $covered[$f] = true;
    }
}
$uncovered = array_values(array_filter($missing, fn (string $f): bool => !isset($covered[$f]) && !in_array($f, M2_EXCLUDED_FROM_SPINE, true)));
$out['coverage'] = [
    'batched' => count($covered),
    'excluded' => count(M2_EXCLUDED_FROM_SPINE),
    'excluded_files' => M2_EXCLUDED_FROM_SPINE,
    'uncovered' => count($uncovered),
    'uncovered_files' => $uncovered,
];

if ($jsonOut) {
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    exit($uncovered === [] ? 0 : 1);
}

echo "Inventory: {$out['inventory_total']}, spine: {$out['spine_total']}, missing: {$out['missing_total']}\n";
echo 'Batches: '.count($out['batches']).", covered: {$out['coverage']['batched']}, uncovered: {$out['coverage']['uncovered']}\n";
if ([] !== $uncovered) {
    echo "UNCOVERED:\n";
    foreach ($uncovered as $f) {
        echo "  $f\n";
    }
    exit(1);
}
echo "OK — all missing files assigned to a batch.\n";
