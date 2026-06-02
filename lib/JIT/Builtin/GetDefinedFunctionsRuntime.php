<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;

/**
 * JIT MCJIT link for __compiler_get_defined_functions_merge (issue #3128).
 */
final class GetDefinedFunctionsRuntime
{
    private const RUNTIME_SOURCE = __DIR__.'/../../AOT/runtime/phpc_get_defined_functions.c';

    public static function ensureLinked(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            return;
        }

        $existing = $context->module->getNamedFunction('__compiler_get_defined_functions_merge');
        if (null !== $existing && $existing->countBasicBlocks() > 0) {
            return;
        }

        $bitcode = self::ensureBitcode();
        $data = file_get_contents($bitcode);
        if (false === $data || '' === $data) {
            throw new \LogicException('Failed to read get_defined_functions JIT bitcode: '.$bitcode);
        }
        $buffer = $context->llvm->createMemoryBufferWithString($data, 'get_defined_functions.bc');
        $runtimeModule = $buffer->parseBitcode($context->context);
        if (!$context->module->link($runtimeModule)) {
            throw new \LogicException('Failed to link get_defined_functions JIT runtime bitcode');
        }

        $fn = $context->module->getNamedFunction('__compiler_get_defined_functions_merge');
        if (null === $fn) {
            throw new \LogicException('__compiler_get_defined_functions_merge missing after bitcode link');
        }
        $context->registerFunction('__compiler_get_defined_functions_merge', $fn);
    }

    private static function ensureBitcode(): string
    {
        $source = realpath(self::RUNTIME_SOURCE);
        if (false === $source || !is_file($source)) {
            throw new \LogicException('get_defined_functions runtime source not found: '.self::RUNTIME_SOURCE);
        }

        $compiler = self::resolveCompiler();
        $cacheDir = sys_get_temp_dir().'/phpc-jit-runtime';
        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0777, true) && !is_dir($cacheDir)) {
            throw new \LogicException('Cannot create JIT runtime cache: '.$cacheDir);
        }

        $namesInc = dirname($source).'/builtin_function_names.inc';
        $cache = $cacheDir.'/get_defined_functions-'.substr(
            sha1($source.filemtime($source).filemtime($namesInc).$compiler),
            0,
            16
        ).'.bc';
        if (is_file($cache) && filemtime($cache) >= filemtime($source) && filemtime($cache) >= filemtime($namesInc)) {
            return $cache;
        }

        $includes = self::includeFlags();
        $runtimeDir = dirname($source);
        $cmd = escapeshellarg($compiler)
            .' -emit-llvm -c -fPIC -O2'.$includes.' -I'.escapeshellarg($runtimeDir).' '
            .escapeshellarg($source).' -o '.escapeshellarg($cache).' 2>&1';
        $output = shell_exec($cmd);
        if (!is_file($cache)) {
            throw new \LogicException(
                'Failed to compile get_defined_functions JIT bitcode: '.trim((string) $output)
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

        throw new \LogicException('No C compiler found for get_defined_functions JIT runtime bitcode');
    }

    private static function includeFlags(): string
    {
        $flags = '';
        $llvmDir = getenv('PHP_COMPILER_LLVM_PATH');
        if (false !== $llvmDir && '' !== $llvmDir) {
            $flags .= ' -I'.escapeshellarg($llvmDir.'/include');
        }
        foreach (self::discoverSystemIncludeDirs() as $dir) {
            $flags .= ' -isystem '.escapeshellarg($dir);
        }
        if (!str_contains($flags, '-isystem') && is_file('/usr/include/stdio.h')) {
            $flags .= ' -isystem /usr/include';
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
