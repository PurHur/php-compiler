<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;

/**
 * JIT MCJIT runtime for spaceship (<=>) on objects and boxed values (#4661).
 *
 * Links {@see lib/AOT/runtime/phpc_spaceship.c} (uses phpc_gc prop counts).
 */
final class SpaceshipRuntime
{
    private const RUNTIME_SOURCE = __DIR__.'/../../AOT/runtime/phpc_spaceship.c';

    public static function ensureLinked(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            return;
        }
        GcCollectCyclesRuntime::ensureLinked($context);
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            return;
        }

        $probe = $context->module->getNamedFunction('__object__compareSpaceship');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $bitcode = self::ensureBitcode();
        $data = file_get_contents($bitcode);
        if (false === $data || '' === $data) {
            throw new \LogicException('Failed to read spaceship JIT bitcode: '.$bitcode);
        }
        $buffer = $context->llvm->createMemoryBufferWithString($data, 'phpc_spaceship.bc');
        $runtimeModule = $buffer->parseBitcode($context->context);
        if (!$context->module->link($runtimeModule)) {
            throw new \LogicException('Failed to link spaceship JIT runtime bitcode');
        }

        self::registerLinkedRuntime($context);
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (['__value__spaceship', '__object__compareSpaceship', '__hashtable__compareSpaceship'] as $fnName) {
            $fn = $context->module->getNamedFunction($fnName);
            if (null !== $fn) {
                $context->registerFunction($fnName, $fn);
            }
        }
        if (null === $context->module->getNamedFunction('__object__compareSpaceship')) {
            throw new \LogicException('__object__compareSpaceship missing after spaceship bitcode link');
        }
    }

    private static function ensureBitcode(): string
    {
        $source = realpath(self::RUNTIME_SOURCE);
        if (false === $source || !is_file($source)) {
            throw new \LogicException('spaceship runtime source not found: '.self::RUNTIME_SOURCE);
        }

        $compiler = self::resolveCompiler();
        $cacheDir = sys_get_temp_dir().'/phpc-jit-runtime';
        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0777, true) && !is_dir($cacheDir)) {
            throw new \LogicException('Cannot create JIT runtime cache: '.$cacheDir);
        }

        $cache = $cacheDir.'/'.basename($source, '.c').'-'.substr(
            sha1($source.filemtime($source).$compiler.'host'),
            0,
            16
        ).'.bc';
        if (is_file($cache) && filemtime($cache) >= filemtime($source)) {
            return $cache;
        }

        $includes = self::hostLibcIncludeFlags();
        $runtimeDir = dirname($source);
        $cmd = escapeshellarg($compiler)
            .' -emit-llvm -c -fPIC -O2'.$includes.' -I'.escapeshellarg($runtimeDir).' '
            .escapeshellarg($source).' -o '.escapeshellarg($cache).' 2>&1';
        $output = shell_exec($cmd);
        if (!is_file($cache)) {
            throw new \LogicException(
                'Failed to compile spaceship JIT bitcode: '.trim((string) $output)
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

        throw new \LogicException('No C compiler found for spaceship JIT runtime bitcode');
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
