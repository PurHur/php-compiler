<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * ksort() — sort associative arrays by key (subset of PHP; issue #2271).
 *
 * VM: packed list no-op; string or integer keys sorted in place.
 * JIT/AOT: packed list no-op; string-key hashtable via __hashtable__sortStringKeys.
 */
final class ksort_ extends Internal
{
    public function __construct()
    {
        parent::__construct('ksort');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('ksort() requires exactly one argument');
        }
        $array = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $array->type) {
            throw new \LogicException('ksort() argument must be an array in this compiler build');
        }
        $ht = $array->toArray();
        if (VmArray::isList($ht) || $ht->getNumElements() < 2) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(true);
            }

            return;
        }
        $pairs = [];
        foreach ($ht->iterateKeyed(true) as [$key, $value]) {
            $keyCopy = new Variable();
            $keyCopy->copyFrom($key);
            $valCopy = new Variable();
            $valCopy->copyFrom($value);
            $pairs[] = [$keyCopy, $valCopy];
        }
        VmInternalCompare::sortKeyedPairsByKey($pairs);
        $sorted = new HashTable();
        foreach ($pairs as [$key, $value]) {
            $resolvedKey = $key->resolveIndirect();
            $copy = new Variable();
            $copy->copyFrom($value);
            if (Variable::TYPE_INTEGER === $resolvedKey->type) {
                $sorted->addIndex($resolvedKey->toInt(), $copy);
            } elseif (Variable::TYPE_STRING === $resolvedKey->type) {
                $sorted->add($resolvedKey->toString(), $copy);
            } else {
                throw new \LogicException(
                    'ksort() only supports homogeneous string or integer keys in this compiler build'
                );
            }
        }
        $array->array($sorted);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('ksort() requires exactly one argument');
        }
        ArrayBuiltinHelper::ksort($context, $args[0]);

        return $context->getTypeFromString('int1')->constInt(1, false);
    }
}
