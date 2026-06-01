<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;

/**
 * JIT MCJIT bodies for __compiler_ini_get / __compiler_ini_set — link shared AOT runtime (#1374, #1492).
 */
final class IniRuntime
{
    private const RUNTIME_SOURCE = __DIR__.'/../../AOT/runtime/phpc_ini_set.c';

    public static function ensureLinked(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            return;
        }

        $get = $context->module->getNamedFunction('__compiler_ini_get');
        $set = $context->module->getNamedFunction('__compiler_ini_set');
        $errorReporting = $context->module->getNamedFunction('__compiler_error_reporting');
        $beginSilence = $context->module->getNamedFunction('__compiler_begin_silence');
        $endSilence = $context->module->getNamedFunction('__compiler_end_silence');
        if (
            null !== $get && $get->countBasicBlocks() > 0
            && null !== $set && $set->countBasicBlocks() > 0
            && null !== $errorReporting && $errorReporting->countBasicBlocks() > 0
            && null !== $beginSilence && $beginSilence->countBasicBlocks() > 0
            && null !== $endSilence && $endSilence->countBasicBlocks() > 0
        ) {
            return;
        }

        $bitcode = self::ensureBitcode();
        $data = file_get_contents($bitcode);
        if (false === $data || '' === $data) {
            throw new \LogicException('Failed to read ini JIT bitcode: '.$bitcode);
        }
        $buffer = $context->llvm->createMemoryBufferWithString($data, 'ini.bc');
        $runtimeModule = $buffer->parseBitcode($context->context);
        if (!$context->module->link($runtimeModule)) {
            throw new \LogicException('Failed to link ini JIT runtime bitcode');
        }

        $get = $context->module->getNamedFunction('__compiler_ini_get');
        $set = $context->module->getNamedFunction('__compiler_ini_set');
        $errorReporting = $context->module->getNamedFunction('__compiler_error_reporting');
        $beginSilence = $context->module->getNamedFunction('__compiler_begin_silence');
        $endSilence = $context->module->getNamedFunction('__compiler_end_silence');
        if (null === $get || null === $set || null === $errorReporting || null === $beginSilence || null === $endSilence) {
            throw new \LogicException('__compiler_ini_get/set/error_reporting/silence missing after ini runtime bitcode link');
        }
        $context->registerFunction('__compiler_ini_get', $get);
        $context->registerFunction('__compiler_ini_set', $set);
        $context->registerFunction('__compiler_error_reporting', $errorReporting);
        $context->registerFunction('__compiler_begin_silence', $beginSilence);
        $context->registerFunction('__compiler_end_silence', $endSilence);
    }

    private static function ensureBitcode(): string
    {
        $source = realpath(self::RUNTIME_SOURCE);
        if (false === $source || !is_file($source)) {
            throw new \LogicException('ini runtime source not found: '.self::RUNTIME_SOURCE);
        }

        $compiler = self::resolveCompiler();
        $cacheDir = sys_get_temp_dir().'/phpc-jit-runtime';
        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0777, true) && !is_dir($cacheDir)) {
            throw new \LogicException('Cannot create JIT runtime cache: '.$cacheDir);
        }

        $namesInc = dirname($source).'/builtin_function_names.inc';
        $cache = $cacheDir.'/ini-'.substr(
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
                'Failed to compile ini JIT bitcode: '.trim((string) $output)
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

        throw new \LogicException('No C compiler found for ini JIT runtime bitcode');
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
