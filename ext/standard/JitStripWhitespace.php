<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringFileGetContents;
use PHPCompiler\JIT\Builtin\StripWhitespace;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\PathSupport;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for php_strip_whitespace() (#3262). */
final class JitStripWhitespace
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (1 !== $argc) {
            throw new \LogicException('php_strip_whitespace() expects exactly 1 argument in this compiler build');
        }

        $pathLit = $args[0]->compileTimeString ?? null;
        $blockedEarly = JitStreamIncludeOpen::rejectCompileTimeBlockedScriptOpen(
            $context,
            $pathLit,
            'php_strip_whitespace',
            false,
            false
        );
        if (null !== $blockedEarly) {
            return $blockedEarly;
        }

        if (null !== $pathLit) {
            if ('' === $pathLit) {
                throw new \ValueError(PathSupport::EMPTY_PATH_VALUE_ERROR_MESSAGE);
            }
            $contents = VmFsReadNative::available()
                ? VmFsReadNative::read($pathLit)
                : false;
            if (false === $contents) {
                return self::materializeString($context, '');
            }

            return self::materializeString($context, VmStripWhitespace::stripSource($contents));
        }

        StripWhitespace::ensureLinked($context);
        StringFileGetContents::implement($context);
        $pathStr = JitStreamPath::lowerNonEmptyPath($context, $args[0], 'php_strip_whitespace', 0, 'filename');

        return JitStreamIncludeOpen::wrapWithRuntimeBlockedGuard(
            $context,
            $pathStr,
            'php_strip_whitespace',
            false,
            static fn (Context $ctx): Value => JitStreamIncludeOpen::materializeEmptyString($ctx),
            static fn (Context $ctx): Value => self::lowerReadAndStrip($ctx, $pathStr)
        );
    }

    private static function lowerReadAndStrip(Context $context, Value $pathStr): Value
    {
        $contents = $context->builder->call(
            $context->lookupFunction('__compiler_file_get_contents'),
            $pathStr
        );
        $strPtrTy = $context->getTypeFromString('__string__*');
        $readFailed = $context->builder->icmp(Builder::INT_EQ, $contents, $strPtrTy->constNull());

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $failBlock = BasicBlockHelper::append($context, 'php_strip_whitespace_missing');
        $okBlock = BasicBlockHelper::append($context, 'php_strip_whitespace_read_ok');
        $doneBlock = BasicBlockHelper::append($context, 'php_strip_whitespace_done');
        $context->builder->branchIf($readFailed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            self::emptyOwnedString($context)
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $stripped = $context->builder->call(StripWhitespace::helperFunction($context), $contents);
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $stripped);
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    private static function emptyOwnedString(Context $context): Value
    {
        return $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $context->builder->load($context->constantStringFromString(''))
        );
    }

    private static function materializeString(Context $context, string $value): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $context->builder->load($context->constantStringFromString($value))
        );
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);

        return $ptr;
    }
}
