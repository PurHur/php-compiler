<?php

declare(strict_types=1);

namespace PHPCompiler\AOT;

use PHPCompiler\JIT\AotDebugSymbols;

/**
 * Link an LLVM object file into a standalone executable using the bundled toolchain.
 */
final class Linker
{
    /**
     * Bundled C runtime objects for AOT link.
     *
     * phpc_progress.c is a frozen thin ABI only (#7146, #7360): async-signal-safe SIGSEGV handler
     * that write(2)s phpc_last_progress globals filled by ProgressNoteRuntime.php.
     * Do not add progress formatting or buffer writes in C — use lib/JIT/Builtin/ProgressNoteRuntime.php.
     * Opt out via PHP_COMPILER_PROGRESS_ABI=0 (see runtimeCSources()).
     *
     * @var list<string>
     */
    private const RUNTIME_C_SOURCES = [
        __DIR__.'/runtime/phpc_progress.c',
        __DIR__.'/runtime/phpc_preg_expand.c',
    ];

    /** libz.so symlink is often absent without zlib1g-dev; link the versioned .so directly. */
    private const RUNTIME_LINK_LIBS = '-lpcre2-8 -lcrypt -l:libz.so.1 -l:libzstd.so.1 -l:libbz2.so.1.0';

    /** Host multiarch lib dir for bundled LLVM ld (libz.so.1 lives here, not in LLVM sysroot). */
    private const HOST_LIB_SEARCH = '-L/usr/lib/x86_64-linux-gnu';

    /** Runtime units that need host libc headers layered on the LLVM sysroot (incomplete headers). */
    private const RUNTIME_HOST_LIBC_BASENAMES = [
        'phpc_progress.c',
        'phpc_preg_expand.c',
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
            // Runtime objects before the LLVM object so C overlays (e.g. #3166) override empty JIT stubs.
            $objects = [];
            foreach ($runtimeObjects as $runtimeObject) {
                $objects[] = escapeshellarg($runtimeObject);
            }
            $objects[] = escapeshellarg($objectFile);
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
                self::HOST_LIB_SEARCH,
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
            $objects = '';
            foreach ($runtimeObjects as $runtimeObject) {
                $objects .= ' '.escapeshellarg($runtimeObject);
            }
            $objects .= ' '.escapeshellarg($objectFile);
            foreach ($vendorObjects as $vendorObject) {
                $objects .= ' '.escapeshellarg($vendorObject);
            }
            // When linking with the bundled clang, ensure we can still resolve host libraries
            // (libpcre2-8, libcrypt, ...). Some bootstrap envs only ship the runtime .so/.a under
            // /usr/lib/x86_64-linux-gnu without a full sysroot lib tree.
            $cmd = escapeshellarg($clang).' '.AotDebugSymbols::linkFlag().$objects.' '.self::HOST_LIB_SEARCH.' -lm '.self::RUNTIME_LINK_LIBS.' -o '.escapeshellarg($executable);
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
        foreach (self::runtimeCSources() as $source) {
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
     * @return list<string>
     */
    private static function runtimeCSources(): array
    {
        if (self::progressAbiEnabled()) {
            return self::RUNTIME_C_SOURCES;
        }

        return array_values(array_filter(
            self::RUNTIME_C_SOURCES,
            static fn (string $source): bool => !str_ends_with($source, 'phpc_progress.c')
        ));
    }

    private static function progressAbiEnabled(): bool
    {
        $flag = getenv('PHP_COMPILER_PROGRESS_ABI');
        if (false === $flag || '' === $flag) {
            return true;
        }

        return '0' !== $flag && 'false' !== strtolower($flag);
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
        return $flags;
    }

    private static function runtimeCIncludeFlags(): string
    {
        $flags = '';
        $llvmDir = getenv('PHP_COMPILER_LLVM_PATH');
        if (false !== $llvmDir && '' !== $llvmDir) {
            $sysroot = $llvmDir.'/sysroot';
            if (is_file($sysroot.'/usr/include/stdio.h')) {
                $flags = ' --sysroot='.escapeshellarg($sysroot);
                $flags .= self::runtimeCSysrootGccIncludeFlags($sysroot);
            }
        }
        $hostFlags = self::runtimeCHostLibcIncludeFlags();
        if ('' !== $hostFlags) {
            return $flags.$hostFlags;
        }

        return '' !== $flags ? $flags : self::runtimeCHostLibcIncludeFlags();
    }

    /**
     * Bundled LLVM sysroots ship stdio.h under usr/include but stddef.h under gcc's include tree.
     */
    private static function runtimeCSysrootGccIncludeFlags(string $sysroot): string
    {
        $flags = '';
        $pattern = rtrim($sysroot, '/').'/usr/lib/gcc/*/*/include';
        foreach (glob($pattern) ?: [] as $dir) {
            if (!is_file($dir.'/stddef.h')) {
                continue;
            }
            $flags .= ' -isystem '.escapeshellarg($dir);
        }

        return $flags;
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
        $objects = '';
        foreach ($runtimeObjects as $runtimeObject) {
            $objects .= ' '.escapeshellarg($runtimeObject);
        }
        $objects .= ' '.escapeshellarg($objectFile);
        foreach ($vendorObjects as $vendorObject) {
            $objects .= ' '.escapeshellarg($vendorObject);
        }
        foreach ($linkers as $linker) {
            $path = self::which($linker);
            if (null === $path) {
                continue;
            }
            $cmd = escapeshellarg($path) . ' '
                . AotDebugSymbols::linkFlag() . $objects . ' '.self::HOST_LIB_SEARCH.' -lm '.self::RUNTIME_LINK_LIBS.' -o ' . escapeshellarg($executable);
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

    /**
     * Resolve the on-disk artifact path after compileToFile() for the requested -o value (#8709).
     */
    public static function resolveEffectiveOutputPath(string $requestedOut): string
    {
        $keepObject = getenv('PHP_COMPILER_KEEP_OBJECT_FILE');
        $vendorPrelink = getenv('PHP_COMPILER_VENDOR_PRELINK');
        $selfhostAot = getenv('PHP_COMPILER_SELFHOST_AOT');
        $vendorObjectOnly = ('1' === $vendorPrelink || 'true' === strtolower((string) $vendorPrelink))
            && ('0' === $selfhostAot || 'false' === strtolower((string) $selfhostAot));
        $keepingObjectOnly = ('1' === $keepObject || 'true' === strtolower((string) $keepObject))
            || $vendorObjectOnly;

        if ($keepingObjectOnly && !str_ends_with($requestedOut, '.o')) {
            return $requestedOut.'.o';
        }

        return $requestedOut;
    }

    /**
     * Inventory argv emit must materialize a non-empty regular file (#3046, #8709).
     *
     * @throws \LogicException when missing, not a regular file, or zero bytes
     */
    public static function assertNonEmptyOutputFile(string $path): void
    {
        if (!is_file($path)) {
            throw new \LogicException(sprintf(
                'compile driver: output file missing after emit: %s (#8709)',
                $path
            ));
        }
        $size = filesize($path);
        if (false === $size || $size <= 0) {
            throw new \LogicException(sprintf(
                'compile driver: output file is empty: %s (#8709)',
                $path
            ));
        }
    }

    /**
     * Verify the -o artifact referenced by bin/compile.php argv drivers (#8709).
     *
     * @throws \LogicException
     */
    public static function assertNonEmptyRequestedOutput(string $requestedOut): void
    {
        self::assertNonEmptyOutputFile(self::resolveEffectiveOutputPath($requestedOut));
    }
}
