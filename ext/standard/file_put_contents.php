<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitOperandTypeLabel;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** file_put_contents() — string data; flags FILE_APPEND (8) and LOCK_EX (2) in JIT (#194, #4275). */
final class file_put_contents extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 4) {
            throw new \ArgumentCountError(
                'file_put_contents() expects at least 2 arguments, '.$argc.' given'
            );
        }
        $dataVar = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        // Z_PARAM_PATH non-empty — same guard as fopen/file_get_contents (#29294 / #29268).
        $path = VmStreamPath::coerceNonEmptyPathArgForFrame($frame, 0, 'file_put_contents', 'filename');
        $flags = 0;
        if (isset($frame->calledArgs[2])) {
            $flags = VmMath::parseIntBuiltinArgForFrame(
                $frame,
                2,
                'file_put_contents',
                3,
                'flags'
            );
        }
        if (isset($frame->calledArgs[3])) {
            $contextVar = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_NULL !== $contextVar->type) {
                VmStreamContext::requireRepresentation($contextVar, 'file_put_contents', 4);
            }
        }
        $data = self::coerceData($dataVar);
        $written = VmFs::filePutContents($path, $data, $flags);
        if (false === $written) {
            VmStreamOpenFailure::warnFailedToOpen($frame, 'file_put_contents', $path);
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($written);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 4) {
            throw new \ArgumentCountError(
                'file_put_contents() expects at least 2 arguments, '.$argc.' given'
            );
        }
        $flags = 0;
        if ($argc >= 3) {
            self::assertFlagsJitArg($context, $args[2]);
            $flagsVal = $args[2]->compileTimeLong ?? null;
            if (null !== $flagsVal) {
                self::assertSupportedFlags($flagsVal);
            }
            $flags = $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[2], 'file_put_contents() flags'),
                $context->getTypeFromString('int64')
            );
        } else {
            $flags = $context->getTypeFromString('int64')->constInt(0, false);
        }

        return JitFilePutContents::invoke(
            $context,
            JitStreamPath::lowerNonEmptyPath($context, $args[0], 'file_put_contents', 0, 'filename'),
            self::lowerDataJitArg($context, $args[1]),
            $flags
        );
    }

    /** @return Value */
    private static function lowerDataJitArg(Context $context, JITVariable $arg): Value
    {
        return JitStringBuiltinArg::lower($context, $arg, 'file_put_contents', 1, 'data');
    }

    private static function assertFlagsJitArg(Context $context, JITVariable $arg): void
    {
        $literal = JitStringArg::compileTimeLiteral($arg);
        if (null !== $literal && ('' === $literal || !is_numeric($literal))) {
            throw new \TypeError('file_put_contents(): Argument #3 ($flags) must be of type int, string given');
        }
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            return;
        }
        if (JITVariable::TYPE_STRING === $arg->type || JITVariable::TYPE_VALUE === $arg->type) {
            return;
        }
        if (\in_array($arg->type, [
            JITVariable::TYPE_NATIVE_BOOL,
            JITVariable::TYPE_NATIVE_DOUBLE,
            JITVariable::TYPE_NULL,
        ], true)) {
            return;
        }
        throw new \TypeError(\sprintf(
            'file_put_contents(): Argument #3 ($flags) must be of type int, %s given',
            JitOperandTypeLabel::givenLabel($context, $arg)
        ));
    }

    /** Zend bitmask: FILE_APPEND (8) | LOCK_EX (2) only (#4275). */
    private static function assertSupportedFlags(int $flags): void
    {
        $known = 2 | 8;
        if (0 !== ($flags & ~$known)) {
            throw new \LogicException(
                'file_put_contents() flags must be 0, LOCK_EX (2), FILE_APPEND (8), or their combination in this compiler build'
            );
        }
    }

    /**
     * @return string|list<string>
     */
    private static function coerceData(Variable $var)
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            VmNullStringParamDeprecation::emit(null, 'file_put_contents', 1, 'data');

            return '';
        }
        if (Variable::TYPE_STRING === $var->type) {
            return $var->toString();
        }
        if (Variable::TYPE_ARRAY === $var->type) {
            $lines = [];
            foreach ($var->toArray()->iterate(true) as $entry) {
                $lines[] = $entry->toString();
            }

            return $lines;
        }
        throw new \LogicException('file_put_contents() data must be a string or array in this compiler build');
    }
}
