<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** strtr() — byte translation table or replace_pairs array (JIT/AOT via JitStrtr). */
final class strtr extends Internal
{
    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $argc = \count($frame->calledArgs);
        if (2 === $argc) {
            $string = $frame->calledArgs[0]->resolveIndirect();
            $pairs = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_STRING !== $string->type) {
                throw new \LogicException('strtr() argument #1 ($str) must be of type string');
            }
            if (Variable::TYPE_ARRAY !== $pairs->type) {
                throw new \LogicException('strtr() argument #2 ($replace_pairs) must be of type array');
            }
            $replacePairs = [];
            foreach ($pairs->toArray()->iterateKeyed(true) as [$keyVar, $valueVar]) {
                $replacePairs[$keyVar->resolveIndirect()->toString()] = $valueVar->resolveIndirect()->toString();
            }
            $frame->returnVar->string(VmString::strtrArray(
                $string->toString(),
                $replacePairs
            ));

            return;
        }
        if (3 === $argc) {
            $string = $frame->calledArgs[0]->resolveIndirect();
            $from = $frame->calledArgs[1]->resolveIndirect();
            $to = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_STRING !== $string->type
                || Variable::TYPE_STRING !== $from->type
                || Variable::TYPE_STRING !== $to->type) {
                throw new \LogicException('strtr() requires string arguments in this compiler build');
            }
            $frame->returnVar->string(VmString::strtr(
                $string->toString(),
                $from->toString(),
                $to->toString()
            ));

            return;
        }
        throw new \LogicException('strtr() expects 2 or 3 arguments, '.(string) $argc.' given');
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) >= 2 && self::isReplacePairsArg($args[1])) {
            return JitStrtr::translateArray(
                $context,
                $this->jitString($context, $args[0], 'strtr() argument #1'),
                $this->loadReplacePairs($context, $args[1])
            );
        }
        if (3 === \count($args)) {
            return JitStrtr::translate(
                $context,
                $this->jitString($context, $args[0], 'strtr() argument #1'),
                $this->jitString($context, $args[1], 'strtr() argument #2'),
                $this->jitString($context, $args[2], 'strtr() argument #3')
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
