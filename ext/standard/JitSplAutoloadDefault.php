<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\SplAutoloadDefaultRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\NamedOptionalCallArgs;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** JIT spl_autoload() / spl_autoload_extensions() lowering (#4256). */
final class JitSplAutoloadDefault
{
    public static function autoload(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(
                'spl_autoload() expects 1 or 2 arguments, '.$argc.' given'
            );
        }
        $className = JitStringBuiltinArg::lower($context, $args[0], 'spl_autoload', 0, 'class_name');
        [$hasFileExts, $fileExts] = self::lowerPresentArg(
            $context,
            $args,
            1,
            'spl_autoload',
            'file_extensions',
            2
        );

        SplAutoloadDefaultRuntime::invokeDefault($context, $className, $hasFileExts, $fileExts);

        return $context->getTypeFromString('int32')->constInt(0, false);
    }

    public static function extensions(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 1) {
            throw new \ArgumentCountError(
                'spl_autoload_extensions() expects at most 1 argument, '.$argc.' given'
            );
        }
        if (0 === $argc || NamedOptionalCallArgs::isOmittedOptional($args[0])) {
            return self::invokeExtensionsGet($context);
        }

        [$hasArg, $fileExts] = self::lowerPresentArg(
            $context,
            $args,
            0,
            'spl_autoload_extensions',
            'file_extensions',
            1
        );

        return SplAutoloadDefaultRuntime::invokeExtensions($context, $hasArg, $fileExts);
    }

    private static function invokeExtensionsGet(Context $context): Value
    {
        $i1 = $context->getTypeFromString('int1');
        $strPtr = $context->getTypeFromString('__string__*');

        return SplAutoloadDefaultRuntime::invokeExtensions(
            $context,
            $i1->constInt(0, false),
            $strPtr->constNull()
        );
    }

    /**
     * @param list<JITVariable> $args
     *
     * @return array{0: Value, 1: Value} hasArg int1, string ptr (nullable)
     */
    private static function lowerPresentArg(
        Context $context,
        array $args,
        int $index,
        string $fn,
        string $paramName,
        int $argNum
    ): array {
        $i1 = $context->getTypeFromString('int1');
        $strPtr = $context->getTypeFromString('__string__*');
        if (!isset($args[$index]) || NamedOptionalCallArgs::isOmittedOptional($args[$index])) {
            return [$i1->constInt(0, false), $strPtr->constNull()];
        }

        $arg = $args[$index];
        if (JITVariable::TYPE_NULL === $arg->type) {
            return [$i1->constInt(0, false), $strPtr->constNull()];
        }

        return [
            $i1->constInt(1, false),
            JitStringBuiltinArg::lower($context, $arg, $fn, $argNum - 1, $paramName),
        ];
    }
}
