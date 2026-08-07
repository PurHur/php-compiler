<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * collator_compare() — procedural alias of Collator::compare (php-src collator_compare.c; #20753).
 *
 * Z_PARAM_STR null TypeError on 8.4 forward (#21077, collator.stub.php).
 */
final class collator_compare extends Internal
{
    public function __construct()
    {
        parent::__construct('collator_compare');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'collator_compare() expects exactly 3 arguments, %d given',
                $argc
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmCollator::isCollatorObject($receiver->toObject())) {
            throw new \TypeError(\sprintf(
                'collator_compare(): Argument #1 ($object) must be of type Collator, %s given',
                Variable::TYPE_OBJECT === $receiver->type
                    ? $receiver->toObject()->class->name
                    : \PHPCompiler\VM\ReflectionSupport::valueTypeLabelPublic($receiver)
            ));
        }
        $string1 = VmCollator::coerceStringArg($frame->calledArgs[1], 'collator_compare', 1, 'string1');
        $string2 = VmCollator::coerceStringArg($frame->calledArgs[2], 'collator_compare', 2, 'string2');
        $result = VmCollator::compare($receiver->toObject(), $string1, $string2);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitCollatorCompare::invokeProcedural($context, ...$args);
    }
}
