<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;

/**
 * JIT MCJIT bodies for spl_autoload_* — link shared AOT runtime (issues #1776, #2441).
 *
 * Links {@see lib/AOT/runtime/phpc_spl_autoload.c}.
 */
final class SplAutoloadOutput
{
    private const RUNTIME_SOURCE = __DIR__.'/../../AOT/runtime/phpc_spl_autoload.c';

    private const RUNTIME_SYMBOLS = [
        '__phpc_spl_autoload_register_apply',
        '__phpc_spl_autoload_dispatch',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            return;
        }

        $probe = $context->module->getNamedFunction('__phpc_spl_autoload_register_apply');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $bitcode = self::ensureBitcode();
        $data = file_get_contents($bitcode);
        if (false === $data || '' === $data) {
            throw new \LogicException('Failed to read spl_autoload JIT bitcode: '.$bitcode);
        }
        $buffer = $context->llvm->createMemoryBufferWithString($data, 'phpc_spl_autoload.bc');
        $runtimeModule = $buffer->parseBitcode($context->context);
        if (!$context->module->link($runtimeModule)) {
            throw new \LogicException('Failed to link spl_autoload JIT runtime bitcode');
        }

        self::registerLinkedRuntime($context);
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_SYMBOLS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException("{$name} missing after phpc_spl_autoload bitcode link");
            }
            $context->registerFunction($name, $fn);
        }
    }

    private static function ensureBitcode(): string
    {
        $source = realpath(self::RUNTIME_SOURCE);
        if (false === $source || !is_file($source)) {
            throw new \LogicException('phpc_spl_autoload runtime source not found: '.self::RUNTIME_SOURCE);
        }

        $compiler = self::resolveCompiler();
        $cacheDir = sys_get_temp_dir().'/phpc-jit-runtime';
        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0777, true) && !is_dir($cacheDir)) {
            throw new \LogicException('Cannot create JIT runtime cache: '.$cacheDir);
        }

        $cache = $cacheDir.'/phpc_spl_autoload-'.substr(sha1($source.filemtime($source).$compiler), 0, 16).'.bc';
        if (is_file($cache) && filemtime($cache) >= filemtime($source)) {
            return $cache;
        }

        $includes = self::hostLibcIncludeFlags();
        $cmd = escapeshellarg($compiler)
            .' -emit-llvm -c -fPIC -O2'.$includes.' '
            .escapeshellarg($source).' -o '.escapeshellarg($cache).' 2>&1';
        $output = shell_exec($cmd);
        if (!is_file($cache)) {
            throw new \LogicException(
                'Failed to compile phpc_spl_autoload JIT bitcode: '.trim((string) $output)
            );
        }

        return $cache;
    }

    private static function resolveCompiler(): string
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

        throw new \LogicException('No C compiler found for phpc_spl_autoload JIT runtime bitcode');
    }

    private static function hostLibcIncludeFlags(): string
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
}
