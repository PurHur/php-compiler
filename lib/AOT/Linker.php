<?php

declare(strict_types=1);

namespace PHPCompiler\AOT;

use PHPCompiler\JIT\AotDebugSymbols;

/**
 * Link an LLVM object file into a standalone executable using the bundled toolchain.
 */
final class Linker
{
    /** @var list<string> */
    private const RUNTIME_C_SOURCES = [
        __DIR__.'/runtime/superglobals_refresh.c',
        __DIR__.'/runtime/phpc_env_local.c',
        __DIR__.'/runtime/phpc_pending_headers.c',
        __DIR__.'/runtime/superglobal_name.c',
        __DIR__.'/runtime/function_exists.c',
        __DIR__.'/runtime/phpc_get_defined_functions.c',
        __DIR__.'/runtime/hash_crypto.c',
        __DIR__.'/runtime/phpc_microtime.c',
        __DIR__.'/runtime/phpc_hrtime.c',
        __DIR__.'/runtime/phpc_getdate.c',
        __DIR__.'/runtime/phpc_gettimeofday.c',
        __DIR__.'/runtime/phpc_info.c',
        __DIR__.'/runtime/phpc_strnatcmp.c',
        __DIR__.'/runtime/phpc_strnatcasecmp.c',
        __DIR__.'/runtime/phpc_substr_compare.c',
        __DIR__.'/runtime/phpc_spaceship.c',
        __DIR__.'/runtime/phpc_levenshtein.c',
        __DIR__.'/runtime/phpc_strspn.c',
        __DIR__.'/runtime/phpc_similar_text.c',
        __DIR__.'/runtime/phpc_soundex.c',
        __DIR__.'/runtime/phpc_str_incdec.c',
        __DIR__.'/runtime/phpc_str_word_count.c',
        __DIR__.'/runtime/phpc_base_convert.c',
        __DIR__.'/runtime/phpc_metaphone.c',
        __DIR__.'/runtime/phpc_str_getcsv.c',
        __DIR__.'/runtime/phpc_uniqid.c',
        __DIR__.'/runtime/phpc_strtok.c',
        __DIR__.'/runtime/phpc_nl2br.c',
        __DIR__.'/runtime/compiler_wordwrap.c',
        __DIR__.'/runtime/password_crypto.c',
        __DIR__.'/runtime/crc32.c',
        __DIR__.'/runtime/crc32c.c',
        __DIR__.'/runtime/strtr.c',
        __DIR__.'/runtime/phpc_array_merge_recursive.c',
        __DIR__.'/runtime/phpc_uuencode.c',
        __DIR__.'/runtime/phpc_utf8_latin1.c',
        __DIR__.'/runtime/phpc_string_cslashes.c',
        __DIR__.'/runtime/filter_validate.c',
        __DIR__.'/runtime/phpc_fs_dir.c',
        __DIR__.'/runtime/phpc_count_chars.c',
        __DIR__.'/runtime/phpc_gethostname.c',
        __DIR__.'/runtime/phpc_gethostbynamel.c',
        __DIR__.'/runtime/phpc_network_services.c',
        __DIR__.'/runtime/phpc_getrusage.c',
        __DIR__.'/runtime/phpc_memory.c',
        __DIR__.'/runtime/phpc_upload_temp.c',
        __DIR__.'/runtime/phpc_session_id_storage.c',
        __DIR__.'/runtime/phpc_session_name_storage.c',
        __DIR__.'/runtime/phpc_session_state.c',
        __DIR__.'/runtime/phpc_value_box.c',
        __DIR__.'/runtime/phpc_session_lifecycle.c',
        __DIR__.'/runtime/phpc_session_storage.c',
        __DIR__.'/runtime/phpc_ob_storage.c',
        __DIR__.'/runtime/phpc_ob.c',
        __DIR__.'/runtime/phpc_pow.c',
        __DIR__.'/runtime/phpc_round.c',
        __DIR__.'/runtime/phpc_settype.c',
        __DIR__.'/runtime/phpc_parse_url.c',
        __DIR__.'/runtime/phpc_parse_str.c',
        __DIR__.'/runtime/phpc_compact.c',
        __DIR__.'/runtime/phpc_json_decode.c',
        __DIR__.'/runtime/phpc_unserialize.c',
        __DIR__.'/runtime/phpc_stream.c',
        __DIR__.'/runtime/phpc_gettype.c',
        __DIR__.'/runtime/phpc_object_id.c',
        __DIR__.'/runtime/phpc_process.c',
        __DIR__.'/runtime/preg_match.c',
        __DIR__.'/runtime/phpc_pack.c',
        __DIR__.'/runtime/phpc_unpack.c',
        __DIR__.'/runtime/phpc_ini_set.c',
        __DIR__.'/runtime/phpc_error_handler.c',
        __DIR__.'/runtime/phpc_last_error.c',
        __DIR__.'/runtime/phpc_spl_autoload.c',
        __DIR__.'/runtime/phpc_stream_context.c',
        __DIR__.'/runtime/phpc_debug_backtrace.c',
        __DIR__.'/runtime/phpc_readonly_raise.c',
        __DIR__.'/runtime/phpc_type_error_raise.c',
        __DIR__.'/runtime/phpc_jit_throw.c',
        __DIR__.'/runtime/phpc_attr_registry.c',
        __DIR__.'/runtime/phpc_class_methods.c',
        __DIR__.'/runtime/phpc_reflection_attr.c',
        __DIR__.'/runtime/phpc_cli_argv.c',
        __DIR__.'/runtime/phpc_weakref.c',
        __DIR__.'/runtime/phpc_gc.c',
    ];

    private const RUNTIME_LINK_LIBS = '-lpcre2-8 -lcrypt';

    /** Runtime units that need host libc headers (glob/scandir; llvm sysroot lacks linux/limits.h). */
    private const RUNTIME_HOST_LIBC_BASENAMES = [
        'phpc_fs_dir.c',
        'phpc_gethostname.c',
        'phpc_gethostbynamel.c',
        'phpc_network_services.c',
        'phpc_getrusage.c',
        'phpc_memory.c',
        'phpc_upload_temp.c',
        'preg_match.c',
        'password_crypto.c',
    ];

    private static function which(string $binary): ?string
    {
        if ('' === $binary) {
            return null;
        }
        if (str_contains($binary, '/')) {
            return is_file($binary) && is_executable($binary) ? $binary : null;
        }
        $path = getenv('PATH');
        if (false === $path || '' === $path) {
            return null;
        }
        foreach (explode(':', $path) as $dir) {
            if ('' === $dir) {
                continue;
            }
            $candidate = rtrim($dir, '/').'/'.$binary;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public static function link(string $objectFile, string $executable): void
    {
        $runtimeObjects = self::compileRuntimeObjects($objectFile);
        $vendorObjects = self::resolvePrelinkedVendorObjects();
        $llvmDir = getenv('PHP_COMPILER_LLVM_PATH');
        if (false === $llvmDir || '' === $llvmDir) {
            self::linkWithSystemCompiler($objectFile, $executable, $runtimeObjects, $vendorObjects);

            return;
        }

        $ld = $llvmDir . '/ld';
        $gccDir = $llvmDir . '/gcc/9';
        $crtbegin = $gccDir . '/crtbegin.o';
        $crtend = $gccDir . '/crtend.o';
        $libgcc = $gccDir . '/libgcc.a';

        if (
            is_executable($ld)
            && is_file($crtbegin)
            && is_file($crtend)
            && is_file($libgcc)
        ) {
            $env = self::toolchainEnvironment($llvmDir);
            $objects = [escapeshellarg($objectFile)];
            foreach ($runtimeObjects as $runtimeObject) {
                $objects[] = escapeshellarg($runtimeObject);
            }
            foreach ($vendorObjects as $vendorObject) {
                $objects[] = escapeshellarg($vendorObject);
            }
            $cmd = implode(' ', [
                escapeshellarg($ld),
                AotDebugSymbols::linkFlag(),
                '-dynamic-linker /lib64/ld-linux-x86-64.so.2',
                escapeshellarg('/usr/lib/x86_64-linux-gnu/crt1.o'),
                escapeshellarg($crtbegin),
                escapeshellarg('/usr/lib/x86_64-linux-gnu/crti.o'),
                implode(' ', $objects),
                '-lc',
                '-lm',
                self::RUNTIME_LINK_LIBS,
                escapeshellarg($libgcc),
                escapeshellarg($crtend),
                escapeshellarg('/usr/lib/x86_64-linux-gnu/crtn.o'),
                '-o',
                escapeshellarg($executable),
            ]);
            self::run($cmd, $env);
            self::unlinkIfTemp($runtimeObjects);

            return;
        }

        $clang = $llvmDir . '/clang-9';
        if (is_executable($clang)) {
            $env = self::toolchainEnvironment($llvmDir);
            $objects = escapeshellarg($objectFile);
            foreach ($runtimeObjects as $runtimeObject) {
                $objects .= ' '.escapeshellarg($runtimeObject);
            }
            foreach ($vendorObjects as $vendorObject) {
                $objects .= ' '.escapeshellarg($vendorObject);
            }
            // When linking with the bundled clang, ensure we can still resolve host libraries
            // (libpcre2-8, libcrypt, ...). Some bootstrap envs only ship the runtime .so/.a under
            // /usr/lib/x86_64-linux-gnu without a full sysroot lib tree.
            $cmd = escapeshellarg($clang).' '.AotDebugSymbols::linkFlag().$objects.' -L/usr/lib/x86_64-linux-gnu -lm '.self::RUNTIME_LINK_LIBS.' -o '.escapeshellarg($executable);
            self::run($cmd, $env);
            self::unlinkIfTemp($runtimeObjects);

            return;
        }

        self::linkWithSystemCompiler($objectFile, $executable, $runtimeObjects, $vendorObjects);
    }

    /**
     * @return list<string>
     */
    private static function compileRuntimeObjects(string $objectFile): array
    {
        $objects = [];
        $compiler = self::resolveRuntimeCompiler();
        $llvmDir = getenv('PHP_COMPILER_LLVM_PATH');
        // When we use a host compiler (gcc/cc), prefer the host sysroot/headers.
        // The bundled LLVM sysroot is primarily for clang-9; it can be incomplete in some
        // environments and break libc headers (eg stddef.h).
        $env = null;
        if (false !== $llvmDir && '' !== $llvmDir) {
            $llvmDir = rtrim($llvmDir, '/');
            if (str_starts_with($compiler, $llvmDir.'/')) {
                $env = self::toolchainEnvironment($llvmDir);
            }
        }
        $index = 0;
        foreach (self::RUNTIME_C_SOURCES as $source) {
            if (!is_file($source)) {
                continue;
            }
            $runtimeObject = $objectFile.'.runtime'.$index.'.o';
            ++$index;
            $sourceFlags = self::runtimeCIncludeFlagsFor(basename($source));
            $cmd = escapeshellarg($compiler).' -c -fPIC '.AotDebugSymbols::compileFlag().$sourceFlags.' '
                .escapeshellarg($source).' -o '.escapeshellarg($runtimeObject);
            $captured = self::runCaptured($cmd, $env);
            if (0 !== $captured['code'] || !is_file($runtimeObject)) {
                throw new \LogicException(
                    'Failed to compile AOT runtime: '.trim(
                        $captured['stderr']."\n".$captured['stdout']
                    )
                );
            }
            $objects[] = $runtimeObject;
        }

        return $objects;
    }

    /**
     * Prefer a host gcc/cc so libc headers resolve; fall back to the bundled clang-9.
     */
    private static function resolveRuntimeCompiler(): string
    {
        foreach (['gcc', 'cc', 'clang'] as $name) {
            $path = self::which($name);
            if (null !== $path) {
                return $path;
            }
        }

        return self::resolveBundledClang();
    }

    private static function resolveBundledClang(): string
    {
        $llvmDir = getenv('PHP_COMPILER_LLVM_PATH');
        if (false !== $llvmDir && '' !== $llvmDir) {
            foreach (['clang-9', 'clang'] as $name) {
                $candidate = $llvmDir.'/'.$name;
                if (is_executable($candidate)) {
                    return $candidate;
                }
            }
        }
        foreach (['clang-9', 'clang'] as $name) {
            $path = self::which($name);
            if (null !== $path) {
                return $path;
            }
        }

        throw new \LogicException('No C compiler found for AOT runtime (clang/gcc).');
    }

    private static function runtimeCIncludeFlagsFor(string $basename): string
    {
        if (in_array($basename, self::RUNTIME_HOST_LIBC_BASENAMES, true)) {
            // These units prefer host headers when available, but still require a usable baseline
            // include set. Always include the bundled sysroot (when present) and layer host include
            // dirs on top to fill gaps (eg linux/limits.h).
            $flags = self::runtimeCIncludeFlags();
            $hostFlags = self::runtimeCHostLibcIncludeFlags();
            if ('' !== $hostFlags) {
                $flags .= $hostFlags;
            }
        } else {
            $flags = self::runtimeCIncludeFlags();
        }
        if ('function_exists.c' === $basename || 'phpc_get_defined_functions.c' === $basename) {
            $flags .= ' -I'.escapeshellarg(__DIR__.'/runtime');
        }

        return $flags;
    }

    private static function runtimeCIncludeFlags(): string
    {
        $llvmDir = getenv('PHP_COMPILER_LLVM_PATH');
        if (false !== $llvmDir && '' !== $llvmDir) {
            $sysroot = $llvmDir.'/sysroot';
            if (is_file($sysroot.'/usr/include/stdio.h')) {
                return ' --sysroot='.escapeshellarg($sysroot);
            }
        }

        return self::runtimeCHostLibcIncludeFlags();
    }

    private static function runtimeCHostLibcIncludeFlags(): string
    {
        $flags = '';
        foreach (self::discoverSystemIncludeDirs() as $dir) {
            $flags .= ' -isystem '.escapeshellarg($dir);
        }
        if ('' === $flags && is_file('/usr/include/stdio.h')) {
            $flags = ' -isystem /usr/include';
        }

        return $flags;
    }

    /**
     * @return list<string>
     */
    private static function discoverSystemIncludeDirs(): array
    {
        $dirs = [];
        foreach (['gcc', 'cc', 'clang'] as $compiler) {
            $path = self::which($compiler);
            if (null === $path) {
                continue;
            }
            $captured = self::runCaptured(
                escapeshellarg($path).' -E -Wp,-v -xc /dev/null',
                null
            );
            $verbose = $captured['stderr']."\n".$captured['stdout'];
            if ('' === trim($verbose)) {
                continue;
            }
            $capture = false;
            foreach (explode("\n", $verbose) as $line) {
                if (str_contains($line, '#include <...> search starts here:')) {
                    $capture = true;

                    continue;
                }
                if ($capture) {
                    if (str_contains($line, 'End of search list')) {
                        break;
                    }
                    $dir = trim($line);
                    if ('' !== $dir && is_dir($dir)) {
                        $dirs[$dir] = true;
                    }
                }
            }
            if ([] !== $dirs) {
                break;
            }
        }

        if ([] === $dirs) {
            foreach (['/usr/include', '/usr/include/x86_64-linux-gnu'] as $fallback) {
                if (is_dir($fallback)) {
                    $dirs[$fallback] = true;
                }
            }
        }

        return array_keys($dirs);
    }

    private static function resolveClang(): string
    {
        // Runtime C needs system libc headers; bundled LLVM clang often lacks them.
        foreach (['cc', 'gcc', 'clang'] as $name) {
            $path = self::which($name);
            if (null !== $path) {
                return $path;
            }
        }

        $llvmDir = getenv('PHP_COMPILER_LLVM_PATH');
        $llvmPrefix = (false !== $llvmDir && '' !== $llvmDir) ? realpath($llvmDir) : false;
        // Prefer the host toolchain for runtime C: bundled LLVM clang often lacks system headers.
        foreach (['clang', 'gcc', 'cc'] as $name) {
            $path = self::which($name);
            if (null === $path) {
                continue;
            }
            if (false !== $llvmPrefix && str_starts_with($path, $llvmPrefix)) {
                continue;
            }
            return $path;
        }
        if (false !== $llvmDir && '' !== $llvmDir) {
            foreach (['clang-9', 'clang'] as $name) {
                $candidate = $llvmDir.'/'.$name;
                if (is_executable($candidate)) {
                    return $candidate;
                }
            }
        }

        throw new \LogicException('No C compiler found for AOT runtime (clang/gcc).');
    }

    /**
     * @param list<string>|string|null $runtimeObjects
     */
    private static function unlinkIfTemp($runtimeObjects): void
    {
        if (null === $runtimeObjects) {
            return;
        }
        if (is_string($runtimeObjects)) {
            $runtimeObjects = [$runtimeObjects];
        }
        foreach ($runtimeObjects as $runtimeObject) {
            if (is_file($runtimeObject)) {
                @unlink($runtimeObject);
            }
        }
    }

    private static function toolchainEnvironment(string $llvmDir): array
    {
        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        $env['PATH'] = $llvmDir . ':' . ($env['PATH'] ?? '');
        $env['LD_LIBRARY_PATH'] = $llvmDir . (isset($env['LD_LIBRARY_PATH']) && '' !== $env['LD_LIBRARY_PATH']
            ? ':' . $env['LD_LIBRARY_PATH'] : '');

        return $env;
    }

    /**
     * @param list<string> $runtimeObjects
     * @param list<string> $vendorObjects
     */
    private static function linkWithSystemCompiler(
        string $objectFile,
        string $executable,
        array $runtimeObjects = [],
        array $vendorObjects = []
    ): void {
        $linkers = [
            'clang-9', 'clang', 'clang-17', 'clang-14', 'gcc', 'cc',
        ];
        $objects = escapeshellarg($objectFile);
        foreach ($runtimeObjects as $runtimeObject) {
            $objects .= ' '.escapeshellarg($runtimeObject);
        }
        foreach ($vendorObjects as $vendorObject) {
            $objects .= ' '.escapeshellarg($vendorObject);
        }
        foreach ($linkers as $linker) {
            $path = self::which($linker);
            if (null === $path) {
                continue;
            }
            $cmd = escapeshellarg($path) . ' '
                . AotDebugSymbols::linkFlag() . $objects . ' -lm '.self::RUNTIME_LINK_LIBS.' -o ' . escapeshellarg($executable);
            $captured = self::runCaptured($cmd, null);
            if (0 === $captured['code']) {
                self::unlinkIfTemp($runtimeObjects);

                return;
            }
        }
        self::unlinkIfTemp($runtimeObjects);
        throw new \LogicException(
            'No supported linker found. Run script/install-llvm9.sh or install clang/gcc.'
        );
    }

    /**
     * When vendor prelink is enabled, link precompiled vendor objects into the AOT binary
     * so compiled code can run without a vendor/ tree on disk (M5 cold boot; #1416).
     *
     * @return list<string>
     */
    private static function resolvePrelinkedVendorObjects(): array
    {
        $flag = getenv('PHP_COMPILER_VENDOR_PRELINK');
        if ('1' !== $flag && 'true' !== strtolower((string) $flag)) {
            return [];
        }

        $root = self::projectRoot();
        $paths = self::prelinkedVendorObjectPaths($root);
        if ([] === $paths) {
            return [];
        }

        $abs = [];
        foreach ($paths as $rel) {
            $p = $root.'/'.$rel;
            if (is_file($p)) {
                $abs[] = $p;
            }
        }

        return $abs;
    }

    private static function projectRoot(): string
    {
        // lib/AOT/Linker.php → repo root
        return dirname(__DIR__, 3);
    }

    private static function run(string $command, array $env): void
    {
        $captured = self::runCaptured($command, $env);
        if (0 !== $captured['code']) {
            throw new \LogicException(
                'Linking failed (exit '.$captured['code'].'): '.trim($captured['stderr'])
            );
        }
    }

    /**
     * @return array{code:int,stdout:string,stderr:string}
     */
    private static function runCaptured(string $command, ?array $env): array
    {
        $raw = null === $env ? \phpc_run_command($command) : \phpc_run_command($command, $env);
        if (!\is_array($raw)) {
            return ['code' => 127, 'stdout' => '', 'stderr' => ''];
        }

        return [
            'code' => (int) ($raw['code'] ?? 127),
            'stdout' => \is_string($raw['stdout'] ?? null) ? $raw['stdout'] : '',
            'stderr' => \is_string($raw['stderr'] ?? null) ? $raw['stderr'] : '',
        ];
    }

    /**
     * Repo-relative paths to committed M5 vendor prelink objects (issue #1416).
     *
     * @return list<string>
     */
    public static function prelinkedVendorObjectPaths(string $projectRoot): array
    {
        $manifest = $projectRoot.'/prelinked/bootstrap-vendor/manifest.json';
        if (!is_file($manifest)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($manifest), true);
        if (!is_array($data) || !isset($data['packages']) || !is_array($data['packages'])) {
            return [];
        }
        $paths = [];
        foreach ($data['packages'] as $info) {
            if (!is_array($info)) {
                continue;
            }
            $rel = $info['object'] ?? '';
            if (!is_string($rel) || '' === $rel) {
                continue;
            }
            $abs = $projectRoot.'/'.$rel;
            if (is_file($abs) && ($info['status'] ?? '') === 'object_ok') {
                $paths[] = $rel;
            }
        }

        return $paths;
    }
}
