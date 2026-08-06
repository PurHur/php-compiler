<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\Type\Object_ as ObjectType;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\GeneratorHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * is_iterable() — array or Traversable detection (ext/standard/basic_functions.c, #3313).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/basic_functions.c PHP_FUNCTION(is_iterable)
 */
final class is_iterable extends Internal
{
    /** @var list<string> */
    private const TRAVERSABLE_IFACES = ['traversable', 'iterator', 'iteratoraggregate'];

    public function __construct()
    {
        parent::__construct('is_iterable');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/standard/basic_functions.c — ArgumentCountError (#28317).
        $this->requireExactArgCount($frame, 'is_iterable', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = VmReflection::requireContext($frame);
        $frame->returnVar->bool(
            VmIteratorWalk::isIterable($frame->calledArgs[0], $ctx)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError (AOT try/catch) — peer htmlspecialchars #28285 / #28317.
        if (1 !== \count($args)) {
            $unreachable = $context->constantFromBool(false);
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                \sprintf('is_iterable() expects exactly 1 argument, %d given', \count($args))
            );

            return $unreachable;
        }
        if ($args[0]->type & JITVariable::IS_NATIVE_ARRAY) {
            return $context->constantFromBool(true);
        }
        switch ($args[0]->type) {
            case JITVariable::TYPE_HASHTABLE:
                return $context->constantFromBool(true);
            case JITVariable::TYPE_OBJECT:
                if (GeneratorHelper::isGeneratorVariable($args[0])) {
                    return $context->constantFromBool(true);
                }

                return $context->helper->loadValue(self::jitObjectIterable($context, $args[0]));
            case JITVariable::TYPE_VALUE:
                return $context->helper->loadValue(self::jitBoxedIterable($context, $args[0]));
            case JITVariable::TYPE_NATIVE_LONG:
            case JITVariable::TYPE_NATIVE_DOUBLE:
            case JITVariable::TYPE_NATIVE_BOOL:
            case JITVariable::TYPE_STRING:
            case JITVariable::TYPE_NULL:
                return $context->constantFromBool(false);
            default:
                throw new \LogicException(
                    'is_iterable() does not support this value type in this compiler build'
                );
        }
    }

    private static function jitBoxedIterable(Context $context, JITVariable $arg): JITVariable
    {
        $loaded = JitValueBox::valuePtrFromVariable($context, $arg);
        $typeField = $context->structFieldMap['__value__']['type'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($loaded, $typeField)
        );
        $i8 = $context->getTypeFromString('int8');
        $isArray = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_ARRAY, false)
        );
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_OBJECT, false)
        );
        $objectCheck = self::jitValueBoxObjectIterable($context, $loaded);
        $arrayBool = $context->constantFromBool(true);
        $falseBool = $context->constantFromBool(false);
        $nonObject = $context->builder->select($isArray, $arrayBool, $falseBool);

        return new JITVariable(
            $context,
            JITVariable::TYPE_NATIVE_BOOL,
            JITVariable::KIND_VALUE,
            $context->builder->select($isObject, $objectCheck, $nonObject)
        );
    }

    private static function jitObjectIterable(Context $context, JITVariable $arg): JITVariable
    {
        if (GeneratorHelper::isGeneratorVariable($arg)) {
            return new JITVariable(
                $context,
                JITVariable::TYPE_NATIVE_BOOL,
                JITVariable::KIND_VALUE,
                $context->constantFromBool(true)->value
            );
        }
        $obj = $context->helper->loadValue($arg);
        $objMap = $context->structFieldMap['__object__'];
        $classId = $context->builder->load(
            $context->builder->structGep($obj, $objMap['class_id'])
        );

        return self::jitClassIdIterable($context, $classId);
    }

    private static function jitValueBoxObjectIterable(Context $context, Value $valuePtr): Value
    {
        $obj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
        $objMap = $context->structFieldMap['__object__'];
        $classId = $context->builder->load(
            $context->builder->structGep($obj, $objMap['class_id'])
        );
        $genClassId = self::jitGeneratorClassId($context);
        if (null !== $genClassId) {
            $isGen = $context->builder->icmp(Builder::INT_EQ, $classId, $genClassId);
            $fn = $context->builder->getInsertBlock()->getParent();
            $genBlock = $fn->appendBasicBlock('is_iterable_gen');
            $ifaceBlock = $fn->appendBasicBlock('is_iterable_iface');
            $merge = $fn->appendBasicBlock('is_iterable_merge');
            $context->builder->branchIf($isGen, $genBlock, $ifaceBlock);
            $context->builder->positionAtEnd($genBlock);
            $context->builder->branch($merge);
            $context->builder->positionAtEnd($ifaceBlock);
            $ifaceResult = $context->helper->loadValue(self::jitClassIdIterable($context, $classId));
            $context->builder->branch($merge);
            $context->builder->positionAtEnd($merge);
            $phi = $context->builder->phi($context->getTypeFromString('int1'));
            $phi->addIncoming($context->constantFromBool(true)->value, $genBlock);
            $phi->addIncoming($ifaceResult->value, $ifaceBlock);

            return $phi;
        }
        $result = self::jitClassIdIterable($context, $classId);

        return $context->helper->loadValue($result);
    }

    private static function jitClassIdIterable(Context $context, Value $classId): JITVariable
    {
        $objectType = $context->type->object;
        assert($objectType instanceof ObjectType);
        $i1 = $context->getTypeFromString('int1');
        $acc = $i1->constInt(0, false);
        foreach ($objectType->allClassNamesById() as $id => $name) {
            $classLc = strtolower(ltrim($name, '\\'));
            $matches = false;
            foreach (self::TRAVERSABLE_IFACES as $ifaceLc) {
                if (\in_array($ifaceLc, $objectType->allInterfacesForClassLc($classLc), true)
                    || ($objectType->isInterfaceClassLc($classLc) && $ifaceLc === $classLc)) {
                    $matches = true;
                    break;
                }
            }
            if (!$matches) {
                continue;
            }
            $expected = $context->constantFromInteger($id, 'int64');
            $isId = $context->builder->icmp(Builder::INT_EQ, $classId, $expected);
            $acc = $context->builder->or($acc, $isId);
        }

        return new JITVariable(
            $context,
            JITVariable::TYPE_NATIVE_BOOL,
            JITVariable::KIND_VALUE,
            $acc
        );
    }

    private static function jitGeneratorClassId(Context $context): ?Value
    {
        $objectType = $context->type->object;
        assert($objectType instanceof ObjectType);
        $id = $objectType->lookup('generator');
        if ($id < 0) {
            return null;
        }

        return $context->constantFromInteger($id, 'int64');
    }
}
