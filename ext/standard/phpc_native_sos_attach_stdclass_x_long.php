<?php
declare(strict_types=1);
namespace PHPCompiler\ext\standard;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ParseStrNativeOpsJit;
use PHPCompiler\JIT\Builtin\Type\Object_ as ObjectBuiltin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;
final class phpc_native_sos_attach_stdclass_x_long extends Internal
{
    public function __construct() { parent::__construct('phpc_native_sos_attach_stdclass_x_long'); }
    public function execute(Frame $frame): void {
        throw new \LogicException('phpc_native_sos_attach_stdclass_x_long() is JIT-only (#33876)');
    }
    public function call(Context $context, JITVariable ...$args): Value
    {
        if (3 !== \count($args)) {
            throw new \LogicException('phpc_native_sos_attach_stdclass_x_long() expects 3 arguments');
        }
        /** @var ObjectBuiltin $object */
        $object = $context->type->object;
        $classId = $object->lookup('stdClass');
        $object->defineProperty($classId, 'x', JITVariable::TYPE_VALUE);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'sos_attach_x_alloc');
        $objVal = $object->allocate($classId);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'sos_attach_x_after_alloc');
        $object->markObjectConstructed($objVal);
        $xLong = JitLongArg::lower($context, $args[1], 'sos attach x');
        $box = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            JitValueBox::pointer($context, $box),
            $xLong
        );
        $slot = $object->propertySlotFor($objVal, 'stdClass', 'x');
        $context->builder->store(
            $context->builder->pointerCast(JitValueBox::pointer($context, $box), $context->getTypeFromString('void*')),
            $slot
        );
        $sosHt = ParseStrNativeOpsJit::htPointerFromI64Arg($context, $args[0]);
        $infoLong = JitLongArg::lower($context, $args[2], 'sos attach info');
        $context->builder->call(
            $context->lookupFunction('__hashtable__setObjectKeyLong'),
            $sosHt,
            $objVal,
            $infoLong
        );
        $ret = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            JitValueBox::pointer($context, $ret),
            $context->getTypeFromString('int64')->constInt(0, false)
        );
        return JitValueBox::pointer($context, $ret);
    }
}
