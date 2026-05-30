<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;

/**
 * JIT MCJIT link of native reflection/attribute registry runtime (#1936, #2467).
 */
final class ReflectionRuntime
{
    /** @var list<string> */
    private const RUNTIME_SOURCES = [
        __DIR__.'/../../AOT/runtime/phpc_attr_registry.c',
        __DIR__.'/../../AOT/runtime/phpc_reflection_attr.c',
    ];

    public static function ensureLinked(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            return;
        }
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            return;
        }

        $probe = $context->module->getNamedFunction('phpc_reflect_set_class');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            return;
        }

        foreach (self::RUNTIME_SOURCES as $source) {
            $bitcode = self::ensureBitcode($source);
            $data = file_get_contents($bitcode);
            if (false === $data || '' === $data) {
                throw new \LogicException('Failed to read reflection JIT bitcode: '.$bitcode);
            }
            $buffer = $context->llvm->createMemoryBufferWithString($data, basename($bitcode));
            $runtimeModule = $buffer->parseBitcode($context->context);
            if (!$context->module->link($runtimeModule)) {
                throw new \LogicException('Failed to link reflection JIT runtime: '.$source);
            }
        }

        if (null === $context->module->getNamedFunction('phpc_reflect_set_class')) {
            throw new \LogicException('phpc_reflect_set_class missing after reflection runtime link');
        }

        foreach (
            [
                'phpc_reflect_set_class',
                'phpc_reflect_get_class_name',
                'phpc_reflect_set_method',
                'phpc_reflect_get_method_class',
                'phpc_reflect_get_method_name',
                'phpc_reflect_set_attr_name',
                'phpc_reflect_get_attr_name',
                'phpc_attr_register_class_attrs',
                'phpc_attr_register_method_attrs',
                'phpc_attr_class_count',
                'phpc_attr_class_name_at',
                'phpc_attr_method_count',
                'phpc_attr_method_name_at',
            ] as $fnName
        ) {
            $fn = $context->module->getNamedFunction($fnName);
            if (null !== $fn) {
                $context->registerFunction($fnName, $fn);
            }
        }
    }

    private static function ensureBitcode(string $sourcePath): string
    {
        $source = realpath($sourcePath);
        if (false === $source || !is_file($source)) {
            throw new \LogicException('Reflection runtime source not found: '.$sourcePath);
        }

        $compiler = self::resolveCompiler();
        $cacheDir = sys_get_temp_dir().'/phpc-jit-runtime';
        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0777, true) && !is_dir($cacheDir)) {
            throw new \LogicException('Cannot create JIT runtime cache: '.$cacheDir);
        }

        $base = basename($source, '.c');
        $cache = $cacheDir.'/'.$base.'-'.substr(sha1($source.filemtime($source).$compiler), 0, 16).'.bc';
        if (is_file($cache) && filemtime($cache) >= filemtime($source)) {
            return $cache;
        }

        $includes = self::includeFlags();
        $cmd = escapeshellarg($compiler)
            .' -emit-llvm -c -fPIC -O2'.$includes.' '
            .escapeshellarg($source).' -o '.escapeshellarg($cache).' 2>&1';
        $output = shell_exec($cmd);
        if (!is_file($cache)) {
            throw new \LogicException(
                'Failed to compile reflection JIT bitcode for '.$base.': '.trim((string) $output)
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

        foreach (['clang-9', 'clang'] as $name) {
            $path = trim((string) shell_exec('command -v '.escapeshellarg($name).' 2>/dev/null'));
            if ('' !== $path) {
                return $path;
            }
        }

        throw new \LogicException('No clang compiler found for reflection JIT runtime bitcode');
    }

    private static function includeFlags(): string
    {
        $flags = self::hostLibcIncludeFlags();
        $llvmDir = getenv('PHP_COMPILER_LLVM_PATH');
        if (false !== $llvmDir && '' !== $llvmDir) {
            $flags .= ' -I'.escapeshellarg($llvmDir.'/include');
        }

        return $flags;
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
