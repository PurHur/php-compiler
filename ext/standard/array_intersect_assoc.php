<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

require_once __DIR__.'/VmArrayAssocSetOps.php';

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\ArrayIntersectAssocRuntime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * array_intersect_assoc() — intersect with key match and loose value equality (php-src ext/standard/array.c, #3129).
 */
final class array_intersect_assoc extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \ArgumentCountError('array_intersect_assoc() expects at least 1 argument, 0 given');
        }
        VmArrayAssocSetOps::guardSetOpOperands($frame, $frame->calledArgs, 'array_intersect_assoc');
        $firstHt = $frame->calledArgs[0]->resolveIndirect()->toArray();
        if (1 === $argc) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->array($firstHt->replaceCopy());
            }

            return;
        }
        if (null === $frame->returnVar) {
            return;
        }
        $others = VmArrayAssocSetOps::collectOtherHashTables($frame->calledArgs, 'array_intersect_assoc');
        $out = new HashTable();
        foreach ($firstHt->iterateKeyed(true) as [$key, $value]) {
            if (!VmArrayAssocSetOps::pairInAllOthers($key, $value, $others)) {
                continue;
            }
            $stored = new Variable();
            $stored->copyFrom($value);
            if (Variable::TYPE_INTEGER === $key->type) {
                $out->addIndex($key->toInt(), $stored);
            } else {
                $out->add($key->toString(), $stored);
            }
        }
        $frame->returnVar->array($out);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 1) {
            throw new \ArgumentCountError('array_intersect_assoc() expects at least 1 argument, 0 given');
        }

        TypeErrorRaise::ensureLinked($context);
        foreach ($args as $i => $arg) {
            $bad = self::jitKnownBadArrayArgLabel($arg);
            if (null !== $bad) {
                // Compile-time constants: Zend-shaped TypeError at lower time (#19845, cf. #19836).
                if (0 === $i) {
                    throw new \TypeError(
                        'array_intersect_assoc(): Argument #1 ($array) must be of type array, '.$bad.' given'
                    );
                }
                throw new \TypeError(
                    'array_intersect_assoc(): Argument #'.((int) $i + 1).' must be of type array, '.$bad.' given'
                );
            }
            if (JITVariable::TYPE_STRING === $arg->type || JITVariable::TYPE_VALUE === $arg->type) {
                $this->jitString($context, $arg, 'array_intersect_assoc() argument #'.((int) $i + 1));
            }
            if (0 === $i) {
                JitArrayElem::requireArrayParam($context, $arg, 'array_intersect_assoc', 1, 'array');
            } else {
                JitArrayElem::requireArrayArgNum($context, $arg, 'array_intersect_assoc', $i + 1);
            }
        }

        return ArrayIntersectAssocRuntime::intersectAssoc($context, $args[0], ...\array_slice($args, 1));
    }

    /** @return ?string Zend type label when the operand is a compile-time non-array constant */
    private static function jitKnownBadArrayArgLabel(JITVariable $arg): ?string
    {
        if (JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false)) {
            return 'null';
        }
        switch ($arg->type) {
            case JITVariable::TYPE_NATIVE_LONG:
                return 'int';
            case JITVariable::TYPE_NATIVE_DOUBLE:
                return 'float';
            case JITVariable::TYPE_NATIVE_BOOL:
                return 'bool';
            case JITVariable::TYPE_STRING:
                return 'string';
            default:
                return null;
        }
    }
}
