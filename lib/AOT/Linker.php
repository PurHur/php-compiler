<?php

declare(strict_types=1);

namespace PHPCompiler\AOT;

/**
 * Link an LLVM object file into a standalone executable using the bundled toolchain.
 */
final class Linker
{
    public static function link(string $objectFile, string $executable): void
    {
        $llvmDir = getenv('PHP_COMPILER_LLVM_PATH');
        if (false === $llvmDir || '' === $llvmDir) {
            self::linkWithSystemCompiler($objectFile, $executable);

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
            $cmd = implode(' ', [
                escapeshellarg($ld),
                '-dynamic-linker /lib64/ld-linux-x86-64.so.2',
                escapeshellarg('/usr/lib/x86_64-linux-gnu/crt1.o'),
                escapeshellarg($crtbegin),
                escapeshellarg('/usr/lib/x86_64-linux-gnu/crti.o'),
                escapeshellarg($objectFile),
                '-lc',
                escapeshellarg($libgcc),
                escapeshellarg($crtend),
                escapeshellarg('/usr/lib/x86_64-linux-gnu/crtn.o'),
                '-o',
                escapeshellarg($executable),
            ]);
            self::run($cmd, $env);

            return;
        }

        $clang = $llvmDir . '/clang-9';
        if (is_executable($clang)) {
            $env = self::toolchainEnvironment($llvmDir);
            $cmd = escapeshellarg($clang) . ' '
                . escapeshellarg($objectFile) . ' -o ' . escapeshellarg($executable);
            self::run($cmd, $env);

            return;
        }

        self::linkWithSystemCompiler($objectFile, $executable);
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

    private static function linkWithSystemCompiler(string $objectFile, string $executable): void
    {
        $linkers = [
            'clang-9', 'clang', 'clang-17', 'clang-14', 'gcc', 'cc',
        ];
        foreach ($linkers as $linker) {
            $path = trim((string) shell_exec('command -v ' . escapeshellarg($linker) . ' 2>/dev/null'));
            if ('' === $path) {
                continue;
            }
            $cmd = escapeshellarg($path) . ' '
                . escapeshellarg($objectFile) . ' -o ' . escapeshellarg($executable);
            exec($cmd, $output, $code);
            if (0 === $code) {
                return;
            }
        }
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
