<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\ext\simplexml\VmSimpleXml;
use PHPCompiler\ext\simplexml\VmSimpleXmlIterator;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\Type\Object_ as ObjectBuiltin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\MagicMethodDispatch;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT lowering shared by intval()/floatval() for array/object operands (#10810, ext/standard/type.c).
 */
final class JitScalarTypeCoerce
{
    public static function hashtableToLong(Context $context, Value $htPtr): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $count = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $htPtr
        );

        return $context->builder->select(
            $context->builder->icmp(Builder::INT_NE, $count, $count->typeOf()->constInt(0, false)),
            $i64->constInt(1, false),
            $i64->constInt(0, false)
        );
    }

    public static function hashtableToDouble(Context $context, Value $htPtr): Value
    {
        $double = $context->getTypeFromString('double');
        $count = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $htPtr
        );
        $nonEmpty = $context->builder->icmp(Builder::INT_NE, $count, $count->typeOf()->constInt(0, false));

        return $context->builder->select(
            $nonEmpty,
            $double->constReal(1.0),
            $double->constReal(0.0)
        );
    }

    /**
     * Zend intval/floatval on plain objects after enum dispatch — E_WARNING + 1 / 1.0 (#10810, type.c).
     *
     * SimpleXMLElement / SimpleXMLIterator: text content → number (sxe_object_cast_ex; #22715).
     *
     * @param 'int'|'float' $kind
     */
    public static function emitPlainObjectToScalar(
        Context $context,
        Value $objPtr,
        string $kind,
        int $errorLevel = ErrorReporter::E_WARNING
    ): Value {
        /** @var ObjectBuiltin $objectBuiltin */
        $objectBuiltin = $context->type->object;
        $map = $context->structFieldMap['__object__'];
        $classId = $context->builder->load(
            $context->builder->structGep($objPtr, $map['class_id'])
        );
        $i64 = $context->getTypeFromString('int64');
        $double = $context->getTypeFromString('double');
        $tag = 'scalar_coerce_obj_'.(string) spl_object_id($context).'_'.$errorLevel;
        $resultTy = 'int' === $kind ? $i64 : $double;

        $entries = [];
        foreach ($objectBuiltin->allClassNamesById() as $id => $name) {
            if ($objectBuiltin->isEnumClassLc(strtolower(ltrim($name, '\\')))) {
                continue;
            }
            $entries[(int) $id] = $name;
        }

        $emitScalar = static function (
            Context $context,
            string $className,
            string $kind,
            Value $objPtr
        ) use ($i64, $double, $objectBuiltin, $errorLevel): Value {
            $sxe = self::tryEmitSimpleXmlNumericCast($context, $objPtr, $className, $kind, $objectBuiltin);
            if (null !== $sxe) {
                return $sxe;
            }
            JitScalarEnumCoerce::emitObjectScalarWarning($context, $className, $kind, $errorLevel);

            return 'int' === $kind ? $i64->constInt(1, false) : $double->constReal(1.0);
        };

        if ([] === $entries) {
            return $emitScalar($context, 'stdClass', $kind, $objPtr);
        }

        $doneBlock = BasicBlockHelper::append($context, $tag.'_done');
        $incoming = [];
        $ids = array_keys($entries);
        $lastIdx = \count($ids) - 1;
        $fallbackBlock = BasicBlockHelper::append($context, $tag.'_fallback');

        foreach ($ids as $idx => $id) {
            $matchBlock = BasicBlockHelper::append($context, $tag.'_match_'.$id);
            $nextBlock = $idx === $lastIdx
                ? $fallbackBlock
                : BasicBlockHelper::append($context, $tag.'_next_'.$id);
            $context->builder->branchIf(
                $context->builder->icmp(
                    Builder::INT_EQ,
                    $classId,
                    $i64->constInt($id, false)
                ),
                $matchBlock,
                $nextBlock
            );
            $context->builder->positionAtEnd($matchBlock);
            $incoming[] = [$emitScalar($context, $entries[$id], $kind, $objPtr), $context->builder->getInsertBlock()];
            $context->builder->branch($doneBlock);
            $context->builder->positionAtEnd($nextBlock);
        }

        $context->builder->positionAtEnd($fallbackBlock);
        $incoming[] = [$emitScalar($context, 'stdClass', $kind, $objPtr), $context->builder->getInsertBlock()];
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($resultTy, $tag.'_phi');
        foreach ($incoming as [$val, $block]) {
            $phi->addIncoming($val, $block);
        }

        return $phi;
    }

    /**
     * SimpleXMLElement numeric cast via __toString text then strtol/strtod (#22715).
     *
     * @param 'int'|'float' $kind
     */
    private static function tryEmitSimpleXmlNumericCast(
        Context $context,
        Value $objPtr,
        string $className,
        string $kind,
        ObjectBuiltin $objectBuiltin
    ): ?Value {
        if (!self::isSimpleXmlNumericCastClass($objectBuiltin, $className)) {
            return null;
        }
        $objVar = new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $objPtr
        );
        $strVar = MagicMethodDispatch::coerceObjectToString($context, $objVar, $className);
        if (null === $strVar) {
            return null;
        }
        $strPtr = $context->helper->loadValue($strVar);
        if ('int' === $kind) {
            return JitZendScalarCast::castStringToInt($context, $strPtr);
        }

        return JitZendScalarCast::castStringToFloat($context, $strPtr);
    }

    private static function isSimpleXmlNumericCastClass(ObjectBuiltin $objectBuiltin, string $className): bool
    {
        $lc = strtolower(ltrim($className, '\\'));
        if (VmSimpleXml::CLASS_LC === $lc || VmSimpleXmlIterator::CLASS_LC === $lc) {
            return true;
        }

        return $objectBuiltin->classIsSubclassOf($lc, VmSimpleXml::CLASS_LC)
            || $objectBuiltin->classIsSubclassOf($lc, VmSimpleXmlIterator::CLASS_LC);
    }
}
