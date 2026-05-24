<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;

/**
 * JIT MCJIT body for __compiler_unserialize — link shared AOT runtime (issue #1175).
 */
final class StringUnserialize
{
    private const RUNTIME_SOURCE = __DIR__.'/../../AOT/runtime/phpc_unserialize.c';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            return;
        }

        $existing = $context->module->getNamedFunction('__compiler_unserialize');
        if (null !== $existing && $existing->countBasicBlocks() > 0) {
            return;
        }

        $bitcode = self::ensureBitcode();
        $data = file_get_contents($bitcode);
        if (false === $data || '' === $data) {
            throw new \LogicException('Failed to read unserialize JIT bitcode: '.$bitcode);
        }
        $buffer = $context->llvm->createMemoryBufferWithString($data, 'unserialize.bc');
        $runtimeModule = $buffer->parseBitcode($context->context);
        if (!$context->module->link($runtimeModule)) {
            throw new \LogicException('Failed to link unserialize JIT runtime bitcode');
        }

        $fn = $context->module->getNamedFunction('__compiler_unserialize');
        if (null === $fn) {
            throw new \LogicException('__compiler_unserialize missing after bitcode link');
        }
        $context->registerFunction('__compiler_unserialize', $fn);
    }

    private static function ensureBitcode(): string
    {
        $source = realpath(self::RUNTIME_SOURCE);
        if (false === $source || !is_file($source)) {
            throw new \LogicException('unserialize runtime source not found: '.self::RUNTIME_SOURCE);
        }

        $compiler = self::resolveCompiler();
        $cacheDir = sys_get_temp_dir().'/phpc-jit-runtime';
        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0777, true) && !is_dir($cacheDir)) {
            throw new \LogicException('Cannot create JIT runtime cache: '.$cacheDir);
        }

        $cache = $cacheDir.'/unserialize-'.substr(sha1($source.filemtime($source).$compiler), 0, 16).'.bc';
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
                'Failed to compile unserialize JIT bitcode: '.trim((string) $output)
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

        throw new \LogicException('No C compiler found for unserialize JIT runtime bitcode');
    }

    private static function includeFlags(): string
    {
        $flags = '';
        $llvmDir = getenv('PHP_COMPILER_LLVM_PATH');
        if (false !== $llvmDir && '' !== $llvmDir) {
            $flags .= ' -I'.escapeshellarg($llvmDir.'/include');
        }

        return $flags;
    }
}
