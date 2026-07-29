<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bcmath;

use PHPCompiler\ext\standard\VmJson;
use PHPCompiler\Frame;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * BcMath\Number::__serialize() — value-only payload (php-src ext/bcmath/bcmath.c; #24614).
 *
 * Zend emits only `value`; scale is recovered on unserialize via php_str2num_ex.
 */
final class NumberSerialize extends BcMathNumberMethod
{
    public function __construct()
    {
        parent::__construct('__serialize');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'BcMath\\Number::__serialize() expects exactly 0 arguments, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        $receiver = $this->receiver($frame, 'BcMath\\Number::__serialize()');
        if (null === $frame->returnVar) {
            return;
        }
        $value = new Variable(Variable::TYPE_STRING);
        $value->string(VmBcMathNumber::valueString($receiver));
        $ht = new HashTable();
        $ht->add(VmBcMathNumber::PROP_VALUE, $value);
        $frame->returnVar->array($ht);
    }
}

/**
 * BcMath\Number::__unserialize(array $data) — derive scale from value (php-src; #24614).
 */
final class NumberUnserialize extends BcMathNumberMethod
{
    public function __construct()
    {
        parent::__construct('__unserialize');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'BcMath\\Number::__unserialize() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        $receiver = $this->receiver($frame, 'BcMath\\Number::__unserialize()');
        $arg = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $arg->type) {
            throw new \TypeError(
                'BcMath\\Number::__unserialize(): Argument #1 ($data) must be of type array, '
                .EnumCaseSupport::typeNameForVariable($arg).' given'
            );
        }

        // Fresh ObjectEntry props are TYPE_STRING prototypes without a scalar (#6357);
        // Zend rejects re-init when intern->num != NULL (php-src bcmath.c __unserialize).
        if (null !== $receiver->getProperty(VmBcMathNumber::PROP_VALUE)->optionalScalarString()) {
            throw new \Error('Cannot modify readonly property BcMath\\Number::$value');
        }

        $data = VmJson::export($arg);
        if (!\is_array($data)
            || !isset($data[VmBcMathNumber::PROP_VALUE])
            || !\is_string($data[VmBcMathNumber::PROP_VALUE])
            || '' === $data[VmBcMathNumber::PROP_VALUE]
        ) {
            throw new \Exception('Invalid serialization data for BcMath\\Number object');
        }

        // Scale is not in the wire payload — derive like php_str2num_ex (#24614).
        VmBcMathNumber::initObject($receiver, $data[VmBcMathNumber::PROP_VALUE]);
    }
}
