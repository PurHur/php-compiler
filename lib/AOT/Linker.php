<?php

declare(strict_types=1);

namespace PHPCompiler\AOT;

/**
 * Link an LLVM object file into a standalone executable using the bundled toolchain.
 */
final class Linker
{
    /** @var list<string> */
    private const RUNTIME_C_SOURCES = [
        __DIR__.'/runtime/superglobals_refresh.c',
        __DIR__.'/runtime/phpc_pending_headers.c',
        __DIR__.'/runtime/superglobal_name.c',
        __DIR__.'/runtime/function_exists.c',
        __DIR__.'/runtime/hash_crypto.c',
        __DIR__.'/runtime/crc32.c',
        __DIR__.'/runtime/strtr.c',
        __DIR__.'/runtime/filter_validate.c',
        __DIR__.'/runtime/phpc_fs_dir.c',
        __DIR__.'/runtime/phpc_session_id_storage.c',
        __DIR__.'/runtime/phpc_session_name_storage.c',
        __DIR__.'/runtime/phpc_value_box.c',
        __DIR__.'/runtime/phpc_session_lifecycle.c',
        __DIR__.'/runtime/phpc_session_storage.c',
        __DIR__.'/runtime/phpc_ob_storage.c',
        __DIR__.'/runtime/phpc_ob.c',
        __DIR__.'/runtime/phpc_parse_url.c',
        __DIR__.'/runtime/phpc_parse_str.c',
        __DIR__.'/runtime/phpc_json_decode.c',
        __DIR__.'/runtime/phpc_unserialize.c',
        __DIR__.'/runtime/phpc_stream.c',
        __DIR__.'/runtime/phpc_process.c',
        __DIR__.'/runtime/preg_match.c',
        __DIR__.'/runtime/phpc_pack.c',
        __DIR__.'/runtime/phpc_ini_set.c',
        __DIR__.'/runtime/phpc_error_handler.c',
    ];

    private const RUNTIME_LINK_LIBS = '-lpcre2-8';

    /** Runtime units that need host libc headers (glob/scandir; llvm sysroot lacks linux/limits.h). */
    private const RUNTIME_HOST_LIBC_BASENAMES = [
        'phpc_fs_dir.c',
        'preg_match.c',
    ];

    public static function link(string $objectFile, string $executable): void
    {
        $runtimeObjects = self::compileRuntimeObjects($objectFile);
        $llvmDir = getenv('PHP_COMPILER_LLVM_PATH');
        if (false === $llvmDir || '' === $llvmDir) {
            self::linkWithSystemCompiler($objectFile, $executable, $runtimeObjects);

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
            $cmd = implode(' ', [
                escapeshellarg($ld),
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
            $cmd = escapeshellarg($clang).' '.$objects.' -lm '.self::RUNTIME_LINK_LIBS.' -o '.escapeshellarg($executable);
            self::run($cmd, $env);
            self::unlinkIfTemp($runtimeObjects);

            return;
        }

        self::linkWithSystemCompiler($objectFile, $executable, $runtimeObjects);
    }

    /**
     * @return list<string>
     */
    private static function compileRuntimeObjects(string $objectFile): array
    {
        $objects = [];
        $compiler = self::resolveRuntimeCompiler();
        $llvmDir = getenv('PHP_COMPILER_LLVM_PATH');
        $env = (false !== $llvmDir && '' !== $llvmDir)
            ? self::toolchainEnvironment($llvmDir)
            : null;
        $index = 0;
        foreach (self::RUNTIME_C_SOURCES as $source) {
            if (!is_file($source)) {
                continue;
            }
            $runtimeObject = $objectFile.'.runtime'.$index.'.o';
            ++$index;
            $sourceFlags = self::runtimeCIncludeFlagsFor(basename($source));
            $cmd = escapeshellarg($compiler).' -c -fPIC -O2'.$sourceFlags.' '
                .escapeshellarg($source).' -o '.escapeshellarg($runtimeObject);
            $descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $proc = proc_open($cmd, $descriptor, $pipes, null, $env);
            if (!is_resource($proc)) {
                throw new \LogicException('Failed to start AOT runtime compiler: '.$cmd);
            }
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($proc);
            if (0 !== $code || !is_file($runtimeObject)) {
                throw new \LogicException(
                    'Failed to compile AOT runtime: '.trim(
                        ($stderr !== false ? $stderr : '')."\n".($stdout !== false ? $stdout : '')
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
            $path = trim((string) shell_exec('command -v '.escapeshellarg($name).' 2>/dev/null'));
            if ('' !== $path) {
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
            $path = trim((string) shell_exec('command -v '.escapeshellarg($name).' 2>/dev/null'));
            if ('' !== $path) {
                return $path;
            }
        }

        throw new \LogicException('No C compiler found for AOT runtime (clang/gcc).');
    }

    private static function runtimeCIncludeFlagsFor(string $basename): string
    {
        $flags = in_array($basename, self::RUNTIME_HOST_LIBC_BASENAMES, true)
            ? self::runtimeCHostLibcIncludeFlags()
            : self::runtimeCIncludeFlags();
        if ('function_exists.c' === $basename) {
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
            $path = trim((string) shell_exec('command -v '.escapeshellarg($compiler).' 2>/dev/null'));
            if ('' === $path) {
                continue;
            }
            $verbose = shell_exec(
                escapeshellarg($path).' -E -Wp,-v -xc /dev/null 2>&1'
            );
            if (!is_string($verbose)) {
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
            $path = trim((string) shell_exec('command -v '.escapeshellarg($name).' 2>/dev/null'));
            if ('' !== $path) {
                return $path;
            }
        }

        $llvmDir = getenv('PHP_COMPILER_LLVM_PATH');
        $llvmPrefix = (false !== $llvmDir && '' !== $llvmDir) ? realpath($llvmDir) : false;
        // Prefer the host toolchain for runtime C: bundled LLVM clang often lacks system headers.
        foreach (['clang', 'gcc', 'cc'] as $name) {
            $path = trim((string) shell_exec('command -v '.escapeshellarg($name).' 2>/dev/null'));
            if ('' === $path) {
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
     */
    private static function linkWithSystemCompiler(
        string $objectFile,
        string $executable,
        array $runtimeObjects = []
    ): void {
        $linkers = [
            'clang-9', 'clang', 'clang-17', 'clang-14', 'gcc', 'cc',
        ];
        $objects = escapeshellarg($objectFile);
        foreach ($runtimeObjects as $runtimeObject) {
            $objects .= ' '.escapeshellarg($runtimeObject);
        }
        foreach ($linkers as $linker) {
            $path = trim((string) shell_exec('command -v ' . escapeshellarg($linker) . ' 2>/dev/null'));
            if ('' === $path) {
                continue;
            }
            $cmd = escapeshellarg($path) . ' '
                . $objects . ' -lm '.self::RUNTIME_LINK_LIBS.' -o ' . escapeshellarg($executable);
            $descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $proc = proc_open($cmd, $descriptor, $pipes, null, null);
            if (!is_resource($proc)) {
                continue;
            }
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($proc);
            if (0 === $code) {
                self::unlinkIfTemp($runtimeObjects);

                return;
            }
        }
        self::unlinkIfTemp($runtimeObjects);
        throw new \LogicException(
            'No supported linker found. Run script/install-llvm9.sh or install clang/gcc.'
        );
    }

    private static function run(string $command, array $env): void
    {
        $descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($command, $descriptor, $pipes, null, $env);
        if (!is_resource($proc)) {
            throw new \LogicException('Failed to start linker: ' . $command);
        }
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if (0 !== $code) {
            throw new \LogicException(
                'Linking failed (exit ' . $code . '): ' . trim($stderr !== false ? $stderr : '')
            );
        }
    }
}
