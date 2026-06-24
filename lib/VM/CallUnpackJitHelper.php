<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Shared call-unpack helpers for VM + JIT compile-time lowering (#10202, php-in-PHP).
 *
 * php-src: Zend/zend_API.c — zend_unpack_args
 * SSOT: {@see CallUnpack}, {@see CallUnpackSupport}
 */
final class CallUnpackJitHelper
{
    /**
     * @param list<array{0: Variable, 1: Variable}> $elements
     */
    public static function vmArrayFromElements(array $elements): Variable
    {
        $array = new Variable(Variable::TYPE_ARRAY);
        $ht = $array->newArray();
        foreach ($elements as [$key, $value]) {
            if (Variable::TYPE_INTEGER === $key->type) {
                $ht->addIndex($key->toInt(), $value);
                continue;
            }
            if (Variable::TYPE_STRING === $key->type) {
                $ht->add($key->toString(), $value);
                continue;
            }
            throw new \LogicException('Unsupported compile-time array key type');
        }

        return $array;
    }
}
