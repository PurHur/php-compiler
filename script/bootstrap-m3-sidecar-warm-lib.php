<?php

declare(strict_types=1);

/**
 * M3 link-time sidecar warmup helpers (parallel via PHP_COMPILER_COMPILE_JOBS).
 */

require_once __DIR__.'/compile-jobs-lib.php';

/**
 * @return list<array{
 *     id: string,
 *     source: string,
 *     sidecar: string,
 *     vendor_object: bool,
 *     memory_limit?: string,
 *     env: array<string, string>,
 *     sequential?: bool
 * }>
 */
function bootstrapM3SidecarWarmJobDefinitions(string $root): array
{
    $common = [
        'PHP_COMPILER_SELFHOST_AOT' => '1',
        'PHP_COMPILER_M3_COMPILE_DRIVER' => '1',
        'PHP_COMPILER_M3_EMIT_SIDECAR_RECURSION_GUARD' => '1',
    ];
    $vendorCommon = [
        'PHP_COMPILER_VENDOR_PRELINK' => '1',
        'PHP_COMPILER_SELFHOST_AOT' => '0',
        'PHP_COMPILER_KEEP_OBJECT_FILE' => '1',
        'PHP_COMPILER_M3_EMIT_HELPER_SPINE' => '1',
        'PHP_COMPILER_M3_EMIT_SIDECAR_RECURSION_GUARD' => '1',
    ];

    return [
        [
            'id' => 'helloworld',
            'source' => 'examples/000-HelloWorld/example.php',
            'sidecar' => 'build/.m3_helloworld_aot_blob',
            'vendor_object' => false,
            'env' => $common,
        ],
        [
            'id' => 'compile-smoke',
            'source' => 'test/bootstrap-aot/compiler_smoke_standalone.php',
            'sidecar' => 'build/.m3_compile_smoke_aot_blob',
            'vendor_object' => false,
            'env' => $common,
        ],
        [
            'id' => 'trivial-echo',
            'source' => 'test/bootstrap-aot/runtime_trivial_echo.php',
            'sidecar' => 'build/.m3_trivial_echo_aot_blob',
            'vendor_object' => false,
            'env' => $common,
        ],
        [
            'id' => 'compiler-minimal',
            'source' => 'test/selfhost/compiler_minimal/main.php',
            'sidecar' => 'build/.m3_compiler_minimal_aot_blob',
            'vendor_object' => false,
            'env' => $common,
        ],
        [
            'id' => 'vendor-php-cfg',
            'source' => 'test/bootstrap-vendor-prelink/generated/ircmaxell-php-cfg_bundle.php',
            'sidecar' => 'build/.m3_vendor_php_cfg_prelink.o',
            'vendor_object' => true,
            'env' => $vendorCommon,
        ],
        [
            'id' => 'vendor-php-types',
            'source' => 'test/bootstrap-vendor-prelink/generated/ircmaxell-php-types_bundle.php',
            'sidecar' => 'build/.m3_vendor_php_types_prelink.o',
            'vendor_object' => true,
            'env' => $vendorCommon,
        ],
        [
            'id' => 'vendor-php-llvm',
            'source' => 'test/bootstrap-vendor-prelink/generated/ircmaxell-php-llvm_bundle.php',
            'sidecar' => 'build/.m3_vendor_php_llvm_prelink.o',
            'vendor_object' => true,
            'env' => $vendorCommon,
        ],
        [
            'id' => 'compiler-lib-spine',
            'source' => 'test/selfhost/compiler_lib_spine_smoke/main.php',
            'sidecar' => 'build/.m3_compiler_lib_aot_blob',
            'vendor_object' => false,
            'memory_limit' => '8192M',
            'env' => array_merge($common, [
                'PHP_COMPILER_LIB_SPINE_BUNDLE' => '1',
                'PHP_COMPILER_MEMORY_LIMIT' => '8192M',
            ]),
            'sequential' => true,
        ],
    ];
}

function bootstrapM3SidecarWarmShouldSkip(string $root, array $job, bool $force): bool
{
    if ($force) {
        return false;
    }
    $sidecarAbs = $root.'/'.$job['sidecar'];
    if (!is_file($sidecarAbs) || !is_readable($sidecarAbs)) {
        return false;
    }
    if ('compiler-lib-spine' === $job['id']) {
        require_once $root.'/lib/JIT/M3EmitTuTrivialEchoAot.php';

        return \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::compilerLibSidecarStampMatches(
            $root,
            $root.'/build/.m3_compiler_lib_sidecar.sha'
        );
    }
    $size = filesize($sidecarAbs);

    return is_int($size) && $size > 0;
}

/**
 * @param array{
 *     id: string,
 *     source: string,
 *     sidecar: string,
 *     vendor_object: bool,
 *     memory_limit?: string,
 *     env: array<string, string>
 * } $job
 */
function bootstrapM3SidecarWarmBuildCommand(string $root, array $job): ?string
{
    $sourceAbs = $root.'/'.$job['source'];
    if (!is_file($sourceAbs)) {
        return null;
    }
    $sidecarAbs = $root.'/'.$job['sidecar'];
    $tmpOut = sys_get_temp_dir().'/m3_sidecar_warm_'.$job['id'].'_'.getmypid();
    @unlink($tmpOut);
    @unlink($tmpOut.'.o');

    $memLimit = $job['memory_limit'] ?? getenv('PHP_COMPILER_MEMORY_LIMIT');
    if (!is_string($memLimit) || '' === $memLimit || '-1' === $memLimit) {
        $memLimit = '4096M';
    }

    $php = escapeshellarg(PHP_BINARY);
    $compile = $php.' -d memory_limit='.escapeshellarg($memLimit)
        .' '.escapeshellarg($root.'/bin/compile.php')
        .' -o '.escapeshellarg($tmpOut)
        .' '.escapeshellarg($sourceAbs);

    $artifact = $job['vendor_object'] ? $tmpOut.'.o' : $tmpOut;
    $install = 'mkdir -p '.escapeshellarg(dirname($sidecarAbs))
        .' && cp -f '.escapeshellarg($artifact).' '.escapeshellarg($sidecarAbs)
        .' && chmod +x '.escapeshellarg($sidecarAbs).' 2>/dev/null || true';

    $envPrefix = '';
    foreach ($job['env'] as $key => $value) {
        $envPrefix .= $key.'='.escapeshellarg($value).' ';
    }

    return $envPrefix.$compile.' 2>&1 && '.$install
        .'; rc=$?; rm -f '.escapeshellarg($tmpOut).' '.escapeshellarg($tmpOut.'.o').'; exit $rc';
}

/**
 * @param list<array{id: string, source: string, sidecar: string, vendor_object: bool, memory_limit?: string, env: array<string, string>, sequential?: bool}> $jobs
 *
 * @return int number of failures
 */
function bootstrapM3SidecarWarmRun(string $root, array $jobs, bool $force = false): int
{
    $parallel = [];
    $sequential = [];
    foreach ($jobs as $job) {
        if (bootstrapM3SidecarWarmShouldSkip($root, $job, $force)) {
            fwrite(STDOUT, "skip {$job['id']} (fresh {$job['sidecar']})\n");
            continue;
        }
        $cmd = bootstrapM3SidecarWarmBuildCommand($root, $job);
        if (null === $cmd) {
            fwrite(STDERR, "skip {$job['id']} (missing {$job['source']})\n");
            continue;
        }
        $entry = ['id' => $job['id'], 'cmd' => $cmd, 'cwd' => $root];
        if (!empty($job['sequential'])) {
            $sequential[] = $entry;
        } else {
            $parallel[] = $entry;
        }
    }

    $jobsCount = php_compiler_compile_jobs();
    if ($jobsCount > 1 && count($parallel) > 1) {
        fwrite(
            STDERR,
            'bootstrap-warm-m3-sidecars: PHP_COMPILER_COMPILE_JOBS='.$jobsCount.' ('.count($parallel)." parallel)\n"
        );
    }

    $failures = 0;
    $handle = static function (array $entry, array $run) use (&$failures, $root): void {
        if (0 === $run['exit']) {
            fwrite(STDOUT, "OK {$entry['id']}\n");
            if ('compiler-lib-spine' === $entry['id']) {
                $sha = @sha1_file($root.'/test/selfhost/compiler_lib_spine_smoke/main.php');
                if (is_string($sha) && '' !== $sha) {
                    @file_put_contents($root.'/build/.m3_compiler_lib_sidecar.sha', $sha);
                }
            }

            return;
        }
        ++$failures;
        fwrite(STDERR, "FAIL {$entry['id']} (exit {$run['exit']})\n");
        $tail = trim($run['output']);
        if ('' !== $tail) {
            $lines = explode("\n", $tail);
            foreach (array_slice($lines, -20) as $line) {
                fwrite(STDERR, '  '.$line."\n");
            }
        }
    };

    if ([] !== $parallel) {
        $results = php_compiler_run_parallel_commands($parallel, $jobsCount);
        foreach ($parallel as $entry) {
            $handle($entry, $results[$entry['id']] ?? ['exit' => 127, 'output' => 'missing result']);
        }
    }
    foreach ($sequential as $entry) {
        $handle($entry, php_compiler_run_command($entry['cmd'], $entry['cwd']));
    }

    return $failures;
}
