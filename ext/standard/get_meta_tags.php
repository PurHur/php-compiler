<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * get_meta_tags() — extract meta name/content pairs from an HTML file (#3703, #4608).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/php_meta_tags.c
 */
final class get_meta_tags extends Internal
{
    public function __construct()
    {
        parent::__construct('get_meta_tags');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('get_meta_tags() expects 1 or 2 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }

        $path = self::vmStringArg($frame, 0, 'filename');
        $useIncludePath = false;
        if ($argc >= 2) {
            $useIncludePath = self::vmBoolArg($frame, 1, 'use_include_path');
        }

        $result = VmMetaTags::getMetaTags($path, $useIncludePath);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $ht = new HashTable();
        foreach ($result as $key => $value) {
            $slot = new Variable();
            $slot->string((string) $value);
            $ht->add((string) $key, $slot);
        }
        $frame->returnVar->array($ht);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('get_meta_tags() expects 1 or 2 arguments in this compiler build');
        }

        $path = self::jitStringArg($context, $args[0], 1, 'filename');
        $useIncludePath = $context->constantFromBool(false);
        if ($argc >= 2) {
            self::jitBoolArg($context, $args[1], 2, 'use_include_path');
            $useIncludePath = JitBoolArg::lower(
                $context,
                $args[1],
                'get_meta_tags(): Argument #2 ($use_include_path)'
            );
        }

        return JitGetMetaTags::invoke($context, $path, $useIncludePath);
    }

    private static function vmStringArg(Frame $frame, int $argIndex, string $paramName): string
    {
        return VmStreamPath::coerceNonEmptyPathArgForFrame(
            $frame,
            $argIndex,
            'get_meta_tags',
            $paramName
        );
    }

    private static function vmBoolArg(Frame $frame, int $argIndex, string $paramName): bool
    {
        if (null !== $frame->parent && $frame->parent->block->strictTypes) {
            return InternalStrictArg::requireBool($frame, $argIndex, 'get_meta_tags', $paramName)->toBool();
        }

        return VmMath::parseBoolBuiltinArg(
            $frame->calledArgs[$argIndex],
            'get_meta_tags',
            $argIndex + 1,
            $paramName
        );
    }

    private static function jitStringArg(
        Context $context,
        JITVariable $arg,
        int $argNumber,
        string $paramName
    ): Value {
        JitInternalStrictArg::requireString($context, $arg, 'get_meta_tags', $paramName, $argNumber);

        return JitStreamPath::lowerNonEmptyPath($context, $arg, 'get_meta_tags', $argNumber - 1, $paramName);
    }

    private static function jitBoolArg(
        Context $context,
        JITVariable $arg,
        int $argNumber,
        string $paramName
    ): void {
        JitInternalStrictArg::requireBool($context, $arg, 'get_meta_tags', $paramName, $argNumber);
    }
}
