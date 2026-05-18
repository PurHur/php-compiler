<?php

declare(strict_types=1);

namespace PHPCompiler\AOT;

/**
 * Link an LLVM object file into a standalone executable using the bundled toolchain.
 */
final class Linker
{
    private const RUNTIME_C = __DIR__.'/runtime/superglobals_refresh.c';

    public static function link(string $objectFile, string $executable): void
    {
        $runtimeObject = self::compileRuntimeObject($objectFile);
        $llvmDir = getenv('PHP_COMPILER_LLVM_PATH');
        if (false === $llvmDir || '' === $llvmDir) {
            self::linkWithSystemCompiler($objectFile, $executable, $runtimeObject);

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
            if (null !== $runtimeObject) {
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
                escapeshellarg($libgcc),
                escapeshellarg($crtend),
                escapeshellarg('/usr/lib/x86_64-linux-gnu/crtn.o'),
                '-o',
                escapeshellarg($executable),
            ]);
            self::run($cmd, $env);
            self::unlinkIfTemp($runtimeObject);

            return;
        }

        $clang = $llvmDir . '/clang-9';
        if (is_executable($clang)) {
            $env = self::toolchainEnvironment($llvmDir);
            $objects = escapeshellarg($objectFile);
            if (null !== $runtimeObject) {
                $objects .= ' '.escapeshellarg($runtimeObject);
            }
            $cmd = escapeshellarg($clang).' '.$objects.' -o '.escapeshellarg($executable);
            self::run($cmd, $env);
            self::unlinkIfTemp($runtimeObject);

            return;
        }

        self::linkWithSystemCompiler($objectFile, $executable, $runtimeObject);
    }

    private static function compileRuntimeObject(string $objectFile): ?string
    {
        if (!is_file(self::RUNTIME_C)) {
            return null;
        }

        $runtimeObject = $objectFile.'.runtime.o';
        $compiler = self::resolveRuntimeCompiler();
        $includeFlags = self::runtimeCIncludeFlags();
        $llvmDir = getenv('PHP_COMPILER_LLVM_PATH');
        $env = (false !== $llvmDir && '' !== $llvmDir)
            ? self::toolchainEnvironment($llvmDir)
            : null;
        $cmd = escapeshellarg($compiler).' -c -fPIC -O2'.$includeFlags.' '
            .escapeshellarg(self::RUNTIME_C).' -o '.escapeshellarg($runtimeObject);
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

        return $runtimeObject;
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

    private static function runtimeCIncludeFlags(): string
    {
        $llvmDir = getenv('PHP_COMPILER_LLVM_PATH');
        if (false !== $llvmDir && '' !== $llvmDir) {
            $sysroot = $llvmDir.'/sysroot';
            if (is_file($sysroot.'/usr/include/stdio.h')) {
                return ' --sysroot='.escapeshellarg($sysroot);
            }
        }

        $flags = '';
        foreach (self::discoverSystemIncludeDirs() as $dir) {
            $flags .= ' -isystem '.escapeshellarg($dir);
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
        $llvmDir = getenv('PHP_COMPILER_LLVM_PATH');
        if (false !== $llvmDir && '' !== $llvmDir) {
            foreach (['clang-9', 'clang'] as $name) {
                $candidate = $llvmDir.'/'.$name;
                if (is_executable($candidate)) {
                    return $candidate;
                }
            }
        }
        foreach (['clang-9', 'clang', 'gcc', 'cc'] as $name) {
            $path = trim((string) shell_exec('command -v '.escapeshellarg($name).' 2>/dev/null'));
            if ('' !== $path) {
                return $path;
            }
        }

        throw new \LogicException('No C compiler found for AOT runtime (clang/gcc).');
    }

    private static function unlinkIfTemp(?string $runtimeObject): void
    {
        if (null !== $runtimeObject && is_file($runtimeObject)) {
            @unlink($runtimeObject);
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

    private static function linkWithSystemCompiler(
        string $objectFile,
        string $executable,
        ?string $runtimeObject = null
    ): void {
        $linkers = [
            'clang-9', 'clang', 'clang-17', 'clang-14', 'gcc', 'cc',
        ];
        $objects = escapeshellarg($objectFile);
        if (null !== $runtimeObject) {
            $objects .= ' '.escapeshellarg($runtimeObject);
        }
        foreach ($linkers as $linker) {
            $path = trim((string) shell_exec('command -v ' . escapeshellarg($linker) . ' 2>/dev/null'));
            if ('' === $path) {
                continue;
            }
            $cmd = escapeshellarg($path) . ' '
                . $objects . ' -o ' . escapeshellarg($executable);
            exec($cmd, $output, $code);
            if (0 === $code) {
                self::unlinkIfTemp($runtimeObject);

                return;
            }
        }
        self::unlinkIfTemp($runtimeObject);
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
