<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionReference::fromArrayElement($array, $key) — static (ext/reflection/php_reflection.c, #22065). */
final class ReflectionReferenceFromArrayElement extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('fromArrayElement');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc !== 2) {
            throw new \ArgumentCountError(sprintf(
                'ReflectionReference::fromArrayElement() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $arrayArg = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $arrayArg->type) {
            throw new \TypeError(
                'ReflectionReference::fromArrayElement(): Argument #1 ($array) must be of type array, '
                .EnumCaseSupport::typeNameForVariable($arrayArg).' given'
            );
        }
        $keyArg = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_STRING !== $keyArg->type && Variable::TYPE_INTEGER !== $keyArg->type) {
            throw new \TypeError(
                'ReflectionReference::fromArrayElement(): Argument #2 ($key) must be of type string|int, '
                .EnumCaseSupport::typeNameForVariable($keyArg).' given'
            );
        }

        $table = $arrayArg->toArray();
        $item = self::lookupBucketValue($table, $keyArg);
        if (null === $item) {
            ReflectionSupport::throwReflectionException('Array key not found');
        }

        if (!\PHPCompiler\VM\ReflectionReferenceSupport::bucketValueIsReference($item)
            || \PHPCompiler\VM\ReflectionReferenceSupport::isIgnorableArrayReference($table, $item)) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->null();
            }

            return;
        }

        $ctx = VmReflection::requireContext($frame);
        $obj = VmReflection::newReflectionReference($ctx, $item);
        if (null !== $frame->returnVar) {
            $frame->returnVar->object($obj);
        }
    }

    private static function lookupBucketValue(HashTable $ht, Variable $keyArg): ?Variable
    {
        if (Variable::TYPE_INTEGER === $keyArg->type) {
            return $ht->findIndex($keyArg->toInt());
        }
        if (Variable::TYPE_STRING === $keyArg->type) {
            return $ht->find($keyArg->toString());
        }

        return null;
    }
}
