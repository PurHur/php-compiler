<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** strtr() — byte translation table or replace_pairs array (JIT/AOT via JitStrtr). */
final class strtr extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 === $argc) {
            $string = VmString::coerceStringBuiltinArg(
                $frame->calledArgs[0],
                'strtr',
                0,
                'string'
            );
            $pairs = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_ARRAY !== $pairs->type) {
                throw new \TypeError(\sprintf(
                    'strtr(): Argument #2 ($replace_pairs) must be of type array, %s given',
                    match ($pairs->type) {
                        Variable::TYPE_NULL => 'null',
                        Variable::TYPE_BOOLEAN => 'bool',
                        Variable::TYPE_INTEGER => 'int',
                        Variable::TYPE_FLOAT => 'float',
                        Variable::TYPE_STRING => 'string',
                        Variable::TYPE_OBJECT => 'object',
                        default => 'mixed',
                    }
                ));
            }
            $replacePairs = [];
            foreach ($pairs->toArray()->iterateKeyed(true) as [$keyVar, $valueVar]) {
                $replacePairs[VmString::coerceStringBuiltinArg($keyVar, 'strtr', 1, 'replace_pairs')] =
                    VmString::coerceStringBuiltinArg($valueVar, 'strtr', 1, 'replace_pairs');
            }
            $result = VmString::strtrArray($string, $replacePairs);
            BuiltinExecute::writeReturn($frame, static fn (Variable $ret) => $ret->string($result));

            return;
        }
        if (3 === $argc) {
            $string = VmString::coerceStringBuiltinArg(
                $frame->calledArgs[0],
                'strtr',
                0,
                'string'
            );
            $from = VmString::coerceStringBuiltinArg(
                $frame->calledArgs[1],
                'strtr',
                1,
                'from'
            );
            $to = VmString::coerceStringBuiltinArg(
                $frame->calledArgs[2],
                'strtr',
                2,
                'to'
            );
            $result = VmString::strtr($string, $from, $to);
            BuiltinExecute::writeReturn($frame, static fn (Variable $ret) => $ret->string($result));

            return;
        }
        throw new \LogicException('strtr() expects 2 or 3 arguments, '.(string) $argc.' given');
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) >= 2 && self::isReplacePairsArg($args[1])) {
            return JitStrtr::translateArray(
                $context,
                JitStringBuiltinArg::lower($context, $args[0], 'strtr', 0, 'string'),
                $this->loadReplacePairs($context, $args[1]),
                $args[0],
                $args[1]
            );
        }
        if (3 === \count($args)) {
            return JitStrtr::translate(
                $context,
                JitStringBuiltinArg::lower($context, $args[0], 'strtr', 0, 'string'),
                JitStringBuiltinArg::lower($context, $args[1], 'strtr', 1, 'from'),
                JitStringBuiltinArg::lower($context, $args[2], 'strtr', 2, 'to'),
                $args[0],
                $args[1],
                $args[2]
            );
        }

        throw new \LogicException('strtr() expects 2 or 3 arguments, '.\count($args).' given');
    }

    private static function isReplacePairsArg(JITVariable $arg): bool
    {
        if (JITVariable::TYPE_HASHTABLE === $arg->type) {
            return true;
        }
        if (0 !== ($arg->type & JITVariable::IS_NATIVE_ARRAY)) {
            return true;
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return true;
        }

        return false;
    }

    private function loadReplacePairs(Context $context, JITVariable $arg): Value
    {
        return ArrayBuiltinHelper::loadHashTable($context, $arg);
    }
}
