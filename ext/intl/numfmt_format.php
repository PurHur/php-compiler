<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** numfmt_format() — procedural NumberFormatter::format (#20754). */
final class numfmt_format extends Internal
{
    public function __construct()
    {
        parent::__construct('numfmt_format');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'numfmt_format() expects between 2 and 3 arguments, %d given',
                $argc
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmNumberFormatter::isFormatterObject($receiver->toObject())) {
            throw new \TypeError(\sprintf(
                'numfmt_format(): Argument #1 ($formatter) must be of type NumberFormatter, %s given',
                Variable::TYPE_OBJECT === $receiver->type
                    ? $receiver->toObject()->class->name
                    : \PHPCompiler\VM\ReflectionSupport::valueTypeLabelPublic($receiver)
            ));
        }
        $num = VmNumberFormatter::coerceFloatArg($frame->calledArgs[1], 'numfmt_format', 1, 'num');
        $type = VmNumberFormatter::TYPE_DEFAULT;
        if ($argc >= 3) {
            $type = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[2], 'numfmt_format', 2, 'type');
        }
        $result = VmNumberFormatter::format($receiver->toObject(), $num, $type);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitNumberFormatterFormat::invokeProcedural($context, ...$args);
    }
}

