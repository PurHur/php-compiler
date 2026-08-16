<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\Highlight;
use PHPCompiler\JIT\Builtin\StringFileGetContents;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitNativeString;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\ValueEchoHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for highlight_string() / highlight_file() / show_source() (#3164, #3447, #4824). */
final class JitHighlight
{
    public static function highlightString(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('highlight_string() expects 1 or 2 arguments in this compiler build');
        }

        // Z_PARAM_STR — null TypeError on 8.4 forward profile before const-fold (#20262, re-#18779).
        if (
            (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false))
            && ($context->callerStrictTypes || JitStringBuiltinArg::requiresZparamStrStrictNullOnForwardProfile())
        ) {
            JitStringBuiltinArg::lowerZparamStr($context, $args[0], 'highlight_string', 0, 'string');

            return $context->getTypeFromString('__value__*')->constNull();
        }

        $codeLit = $args[0]->compileTimeString ?? null;
        $returnLit = self::compileTimeReturn($context, $args, $argc);
        if (
            null === $codeLit
            && (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false))
            && (1 === $argc || null !== $returnLit)
        ) {
            // Soft-null on 8.2 profile — coerce to '' and const-fold when $return is known (#20262).
            $codeLit = '';
        }
        // Const-fold HTML only when $return is known (or omitted). Null/$mixed needs Z_PARAM_BOOL (#31383).
        if (null !== $codeLit && (1 === $argc || null !== $returnLit)) {
            return self::materializeHtml($context, HighlightEngine::render($codeLit), $returnLit ?? false);
        }
        if (null !== $codeLit) {
            $htmlStr = $context->builder->call(
                $context->lookupFunction('__string__separate'),
                $context->builder->load($context->constantStringFromString(HighlightEngine::render($codeLit)))
            );

            return self::emitResult($context, $htmlStr, $args, $argc, 'highlight_string');
        }

        $codeStr = JitStringBuiltinArg::lowerZparamStr(
            $context,
            $args[0],
            'highlight_string',
            0,
            'string'
        );
        $htmlStr = self::renderCodeString($context, $codeStr);

        return self::emitResult($context, $htmlStr, $args, $argc, 'highlight_string');
    }

    public static function highlightFile(Context $context, string $functionName, JITVariable ...$args): Value
    {
        // Arity guarded by highlight_file/show_source::call via requireArgCountRangeJit (#30689).
        $argc = \count($args);

        $pathStr = JitStreamPath::lowerNonEmptyPath($context, $args[0], $functionName, 0, 'filename');
        StringFileGetContents::implement($context);
        JitNativeString::ensureInsertBlock($context);
        $contents = $context->builder->call(
            $context->lookupFunction('__compiler_file_get_contents'),
            $pathStr
        );
        $strPtrTy = $context->getTypeFromString('__string__*');
        $readFailed = $context->builder->icmp(Builder::INT_EQ, $contents, $strPtrTy->constNull());

        $failBlock = BasicBlockHelper::append($context, $functionName.'_missing');
        $okBlock = BasicBlockHelper::append($context, $functionName.'_read_ok');
        $doneBlock = BasicBlockHelper::append($context, $functionName.'_done');
        $context->builder->branchIf($readFailed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $failResult = self::emitMissingFileResult($context, $args, $argc, $functionName);
        $failEndBb = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $htmlStr = self::renderCodeString($context, $contents);
        $highlighted = self::emitResult($context, $htmlStr, $args, $argc, $functionName);
        $okEndBb = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $ptrTy = $context->getTypeFromString('__value__*');
        $result = $context->builder->phi($ptrTy);
        $result->addIncoming($failResult, $failEndBb);
        $result->addIncoming($highlighted, $okEndBb);

        return $result;
    }

    /**
     * highlight_file() read failure — Zend returns false when $return is true (#12140).
     *
     * @param list<JITVariable> $args
     */
    private static function emitMissingFileResult(
        Context $context,
        array $args,
        int $argc,
        string $functionName
    ): Value {
        $returnLit = self::compileTimeReturn($context, $args, $argc);
        if (true === $returnLit || false === $returnLit || 1 === $argc) {
            $slot = JitValueBox::alloc($context);
            $ptr = JitValueBox::pointer($context, $slot);
            JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));

            return $ptr;
        }

        $returnBb = BasicBlockHelper::append($context, $functionName.'_missing_return');
        $falseBb = BasicBlockHelper::append($context, $functionName.'_missing_false');
        $doneBb = BasicBlockHelper::append($context, $functionName.'_missing_done');
        $returns = self::boolValForBranch($context, $args[1], $functionName);
        $context->builder->branchIf($returns, $returnBb, $falseBb);

        $context->builder->positionAtEnd($returnBb);
        $returnSlot = JitValueBox::alloc($context);
        $returnPtr = JitValueBox::pointer($context, $returnSlot);
        JitValueBox::writeBool($context, $returnSlot, $context->constantFromBool(false));
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($falseBb);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $ptrTy = $context->getTypeFromString('__value__*');
        $result = $context->builder->phi($ptrTy);
        $result->addIncoming($returnPtr, $returnBb);
        $result->addIncoming($ptr, $falseBb);

        return $result;
    }

    private static function renderCodeString(Context $context, Value $codeStr): Value
    {
        Highlight::ensureLinked($context);
        $html = $context->builder->call(Highlight::helperFunction($context), $codeStr);

        return $context->builder->call($context->lookupFunction('__string__separate'), $html);
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function emitResult(
        Context $context,
        Value $htmlStr,
        array $args,
        int $argc,
        string $functionName
    ): Value {
        $slot = JitValueBox::alloc($context);
        $outPtr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeString'), $outPtr, $htmlStr);

        if (1 === $argc) {
            ValueEchoHelper::echo($context, $outPtr);
            $trueSlot = JitValueBox::alloc($context);
            $truePtr = JitValueBox::pointer($context, $trueSlot);
            $context->builder->call(
                $context->lookupFunction('__value__writeLong'),
                $truePtr,
                $context->getTypeFromString('int64')->constInt(1, false)
            );

            return $truePtr;
        }

        $returns = self::boolValForBranch($context, $args[1], $functionName);
        $returnBb = BasicBlockHelper::append($context, $functionName.'_return_mode');
        $echoBb = BasicBlockHelper::append($context, $functionName.'_echo_mode');
        $doneBb = BasicBlockHelper::append($context, $functionName.'_emit_done');
        $context->builder->branchIf($returns, $returnBb, $echoBb);

        $context->builder->positionAtEnd($returnBb);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($echoBb);
        ValueEchoHelper::echo($context, $outPtr);
        $echoEndBb = $context->builder->getInsertBlock();
        $trueSlot = JitValueBox::alloc($context);
        $truePtr = JitValueBox::pointer($context, $trueSlot);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $truePtr,
            $context->getTypeFromString('int64')->constInt(1, false)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $ptrTy = $context->getTypeFromString('__value__*');
        $result = $context->builder->phi($ptrTy);
        $result->addIncoming($outPtr, $returnBb);
        $result->addIncoming($truePtr, $echoEndBb);

        return $result;
    }

    private static function materializeHtml(Context $context, string $html, bool $return): Value
    {
        if ($return) {
            $slot = JitValueBox::alloc($context);
            $ptr = JitValueBox::pointer($context, $slot);
            $owned = $context->builder->call(
                $context->lookupFunction('__string__separate'),
                $context->builder->load($context->constantStringFromString($html))
            );
            $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);

            return $ptr;
        }

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $context->builder->load($context->constantStringFromString($html))
        );
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);
        ValueEchoHelper::echo($context, $ptr);
        $trueSlot = JitValueBox::alloc($context);
        $truePtr = JitValueBox::pointer($context, $trueSlot);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $truePtr,
            $context->getTypeFromString('int64')->constInt(1, false)
        );

        return $truePtr;
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function compileTimeReturn(Context $context, array $args, int $argc): ?bool
    {
        if ($argc < 2) {
            return null;
        }
        $arg = $args[1];
        if (JITVariable::KIND_VALUE !== $arg->kind) {
            return null;
        }
        $lib = $context->llvm->lib;
        if (JITVariable::TYPE_NATIVE_BOOL === $arg->type) {
            if (null === $lib->LLVMIsAConstantInt($arg->value->value)) {
                return null;
            }

            return 0 !== (int) $lib->LLVMConstIntGetZExtValue($arg->value->value);
        }
        if (JITVariable::TYPE_NATIVE_LONG !== $arg->type) {
            return null;
        }
        if (null === $lib->LLVMIsAConstantInt($arg->value->value)) {
            return null;
        }

        return 0 !== (int) $lib->LLVMConstIntGetZExtValue($arg->value->value);
    }

    private static function boolValForBranch(Context $context, JITVariable $arg, string $functionName): Value
    {
        // Z_PARAM_BOOL: strict TypeError on null; else soft-null DEP+coerce (#31383 / peer print_r #31337).
        return JitBoolArg::lowerCoerceZParamBool($context, $arg, $functionName, 'return', 2);
    }
}
