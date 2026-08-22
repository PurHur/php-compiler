<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ParseStrNativeOpsJit;
use PHPCompiler\JIT\Builtin\Type\Object_ as ObjectBuiltin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * @internal NestedJIT: stdClass from string-key props HT → ArrayObject bag (#33686).
 *
 * AOT `__compiler_unserialize` fails stdClass string props (Error at offset). Pre-declare
 * single-char props on stdClass, copy present HT entries into slots (CastObjectFromHashtable
 * shape). Multi-char prop names need full stdClass unserialize (follow-up).
 *
 * php-src: ext/spl/spl_array.c; Zend stdClass dynamics
 */
final class phpc_native_ht_set_string_key_stdclass_from_ht extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_native_ht_set_string_key_stdclass_from_ht');
    }

    public function execute(Frame $frame): void
    {
        throw new \LogicException(
            'phpc_native_ht_set_string_key_stdclass_from_ht() is JIT-only (#33686)'
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (3 !== \count($args)) {
            throw new \LogicException(
                'phpc_native_ht_set_string_key_stdclass_from_ht() expects 3 arguments'
            );
        }
        /** @var ObjectBuiltin $object */
        $object = $context->type->object;
        $classId = $object->lookup('stdClass');
        foreach (\range('a', 'z') as $ch) {
            $object->defineProperty($classId, (string) $ch, JITVariable::TYPE_VALUE);
        }
        foreach (\range('A', 'Z') as $ch) {
            $object->defineProperty($classId, (string) $ch, JITVariable::TYPE_VALUE);
        }
        for ($d = 0; $d <= 9; ++$d) {
            $object->defineProperty($classId, (string) $d, JITVariable::TYPE_VALUE);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'ao_stdclass_from_ht_alloc');
        $objVal = $object->allocate($classId);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'ao_stdclass_from_ht_after_alloc');
        $object->markObjectConstructed($objVal);

        $propsHt = ParseStrNativeOpsJit::htPointerFromI64Arg($context, $args[2]);
        $voidPtr = $context->getTypeFromString('void*');
        $i1 = $context->getTypeFromString('int1');
        $serial = 0;
        foreach ($object->instancePropertySets($classId) as $propset) {
            $propName = $propset[1];
            if (1 !== \strlen($propName)) {
                continue;
            }
            ++$serial;
            $keyStr = $context->builder->load($context->constantStringFromString($propName));
            $isset = $context->builder->call(
                $context->lookupFunction('__hashtable__offsetIsSetStringKey'),
                $propsHt,
                $keyStr
            );
            $yes = BasicBlockHelper::append($context, 'ao_std_ht_yes_'.$serial);
            $no = BasicBlockHelper::append($context, 'ao_std_ht_no_'.$serial);
            $context->builder->branchIf(
                $context->builder->icmp(Builder::INT_NE, $isset, $i1->constInt(0, false)),
                $yes,
                $no
            );
            $context->builder->positionAtEnd($yes);
            $valEntry = $context->builder->call(
                $context->lookupFunction('__hashtable__readStringKeyValue'),
                $propsHt,
                $keyStr
            );
            $slot = $object->propertySlotFor($objVal, 'stdClass', $propName);
            $context->builder->store(
                $context->builder->pointerCast($valEntry, $voidPtr),
                $slot
            );
            $context->builder->branch($no);
            $context->builder->positionAtEnd($no);
        }

        $objVar = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $objVal);
        ParseStrNativeOpsJit::setStringKeyObject($context, $args[0], $args[1], $objVar);

        return $context->getTypeFromString('int32')->constInt(0, false);
    }
}
