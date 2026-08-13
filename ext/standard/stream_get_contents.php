<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitResourceArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\NamedOptionalCallArgs;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * stream_get_contents() — drain stream from current or given offset (#3142).
 *
 * php-src: ext/standard/file.c — PHP_FUNCTION(stream_get_contents)
 * Excess/missing argc → Zend ArgumentCountError (#30721).
 */
final class stream_get_contents extends Internal
{
    /** php-src ext/standard/file.c — PHP_FUNCTION(stream_get_contents) length range. */
    private const LENGTH_RANGE_ERROR = 'stream_get_contents(): Argument #2 ($length) must be greater than or equal to -1';

    public function execute(Frame $frame): void
    {
        // php-src stub arity: 1..3 (#30721; ext/standard/file.c / file.stub.php).
        $this->requireArgCountRange($frame, 'stream_get_contents', 1, 3);
        $handleVar = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        $handle = VmStreamArg::requireStreamHandle($handleVar, 'stream_get_contents');
        $maxlength = -1;
        $offset = -1;
        if (isset($frame->calledArgs[1])) {
            $maxlength = self::parseLengthArg($frame->calledArgs[1]->resolveIndirect());
            // php-src file.c: if (maxlength < -1) ValueError (#24560).
            if ($maxlength < -1) {
                throw new \ValueError(self::LENGTH_RANGE_ERROR);
            }
        }
        if (isset($frame->calledArgs[2])) {
            $offset = VmMath::parseZParamLongBuiltinArgForFrame(
                $frame,
                2,
                'stream_get_contents',
                3,
                'offset'
            );
        }
        $data = VmFs::streamGetContents($handle, $maxlength, $offset);
        if (false === $data) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($data);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30721).
        if (!$this->requireArgCountRangeJit($context, $args, 'stream_get_contents', 1, 3)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }
        $argc = \count($args);
        JitResourceArg::rejectEnumCaseOperand($context, $args[0], 'stream_get_contents', 0, 'stream');
        if (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false)) {
            JitResourceArg::emitResourceTypeErrorAndAbort($context, 'stream_get_contents', 0, 'stream', 'null');

            return JitValueBox::pointer($context, JitValueBox::alloc($context));
        }
        $i64 = $context->getTypeFromString('int64');
        $handle = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[0], 'stream_get_contents() handle'),
            $i64
        );
        if ($argc >= 2 && !NamedOptionalCallArgs::isOmittedOptional($args[1])) {
            $maxlength = JitStreamGetContents::lowerLengthArg($context, $args[1]);
            JitStreamGetContents::emitRuntimeLengthRangeGuard($context, $maxlength);
        } else {
            $maxlength = $i64->constInt(-1, true);
        }
        if ($argc >= 3 && !NamedOptionalCallArgs::isOmittedOptional($args[2])) {
            $offset = JitStreamGetContents::lowerOffsetArg($context, $args[2]);
        } else {
            $offset = $i64->constInt(-1, true);
        }

        return JitStreamGetContents::invoke($context, $handle, $maxlength, $offset);
    }

    /**
     * php-src: Z_PARAM_LONG_OR_NULL (ext/standard/streamsfuncs.c — stream_get_contents).
     *
     * @throws \TypeError
     */
    private static function parseLengthArg(Variable $var): int
    {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(self::nullableLengthTypeError(
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_NULL === $var->type) {
            return -1;
        }
        if (Variable::TYPE_ARRAY === $var->type) {
            throw new \TypeError(self::nullableLengthTypeError('array'));
        }
        if (Variable::TYPE_OBJECT === $var->type) {
            throw new \TypeError(self::nullableLengthTypeError('object'));
        }
        switch ($var->type) {
            case Variable::TYPE_INTEGER:
                return $var->toInt();
            case Variable::TYPE_BOOLEAN:
                return $var->toBool() ? 1 : 0;
            case Variable::TYPE_FLOAT:
                return (int) $var->toFloat();
            case Variable::TYPE_STRING:
                $s = $var->toString();
                if ('' === $s || !is_numeric($s)) {
                    throw new \TypeError(self::nullableLengthTypeError('string'));
                }

                return (int) $s;
            default:
                throw new \TypeError(
                    self::nullableLengthTypeError(self::vmTypeName($var->type))
                );
        }
    }

    private static function nullableLengthTypeError(string $given): string
    {
        return sprintf(
            'stream_get_contents(): Argument #2 ($length) must be of type ?int, %s given',
            $given
        );
    }

    private static function vmTypeName(int $type): string
    {
        return match ($type) {
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            Variable::TYPE_RESOURCE => 'resource',
            default => 'mixed',
        };
    }
}
