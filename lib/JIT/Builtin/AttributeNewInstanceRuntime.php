<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitNativeString;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * JIT/AOT link for ReflectionAttribute::newInstance() via AttributeNewInstanceJitHelper PHP (#10274).
 *
 * SSOT: {@see \PHPCompiler\VM\AttributeNewInstanceJitHelper}, {@see \PHPCompiler\VM\ReflectionSupport}
 * php-src: ext/reflection/php_reflection.c — ReflectionAttribute::newInstance()
 */
final class AttributeNewInstanceRuntime
{
    private const HELPER_PATH = '/VM/AttributeNewInstanceJitHelper.php';

    private const RESOLVE_CLASS_ID_HELPER = 'PHPCompiler\\VM\\AttributeNewInstanceJitHelper::resolveClassId';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::RESOLVE_CLASS_ID_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        JitVmHelperLink::ensureCompiled($context, self::HELPER_PATH, self::COMPILED_HELPERS, '#10274');
    }

    public static function emitResolveClassId(Context $context, Variable $nameVar): Value
    {
        self::ensureLinked($context);
        $nameVar = JitNativeString::coerce($context, $nameVar);
        $nameStr = $context->helper->loadValue($nameVar);

        $candidates = $context->type->object->allDeclaredClassLowerNames();
        $packedNames = implode("\0", $candidates);
        $ids = [];
        foreach ($candidates as $lc) {
            $ids[] = (string) $context->type->object->lookup($lc);
        }
        $packedIds = implode(',', $ids);

        $packedNamesStr = $context->builder->load($context->constantStringFromString($packedNames));
        $packedIdsStr = $context->builder->load($context->constantStringFromString($packedIds));

        return $context->builder->call(
            JitVmHelperLink::lookupCompiled($context, self::RESOLVE_CLASS_ID_HELPER, '#10274'),
            $nameStr,
            $packedNamesStr,
            $packedIdsStr
        );
    }

    public static function readFirstPositionalArg(Context $context, Variable $argsVar): Variable
    {
        return self::readPositionalArgAt($context, $argsVar, 0);
    }

    /** First ctor arg from ReflectionAttribute::args property (VM parity, #4598, #5816). */
    public static function emitReadCtorArgFromAttrOwner(Context $context, Value $attrObj): Variable
    {
        $argsVar = $context->type->object->propertyFetch($attrObj, 'ReflectionAttribute', 'args');

        return self::readFirstPositionalArg($context, $argsVar);
    }

    public static function readPositionalArgAt(Context $context, Variable $argsVar, int $index): Variable
    {
        if (Variable::TYPE_HASHTABLE === $argsVar->type) {
            $argsHt = $argsVar->value;
        } else {
            $argsHt = HashTableHelper::readHashtableFromValueBox($context, $argsVar);
        }
        $sizeT = $context->getTypeFromString('size_t');
        $entryVar = HashTableHelper::readIndexedToValueBox($context, $argsHt, $sizeT->constInt($index, false));
        $entryHt = HashTableHelper::readHashtableFromValueBox($context, $entryVar);
        $valueKey = $context->builder->load($context->constantStringFromString('value'));

        return HashTableHelper::readStringKeyToValueBox($context, $entryHt, $valueKey);
    }

    /**
     * Promoted ctor params may not assign $this->prop when __construct is invoked from reflection (#3216, #4598).
     */
    public static function emitApplyConstructorPropertyArgs(
        Context $context,
        Value $obj,
        int $classId,
        Variable $ctorArg,
    ): void {
        $propSets = $context->type->object->instancePropertySets($classId);
        if ([] === $propSets) {
            return;
        }
        $className = $context->type->object->classNameForId($classId);
        $propset = $propSets[0];
        $slot = $context->type->object->propertySlotFor($obj, $className, $propset[1]);
        $context->type->object->propertyStore($slot, $ctorArg, $propset[2]);
    }

    public static function boxObject(Context $context, Value $obj): Value
    {
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            JitValueBox::pointer($context, $slot),
            $obj
        );

        return JitValueBox::pointer($context, $slot);
    }

    public static function emitMissingClassError(Context $context): void
    {
        ErrorRaise::registerDeclarations($context);
        ErrorRaise::ensureLinked($context);
        ErrorRaise::emitRaise($context, 'Attribute class not found');
    }

    /**
     * Args present but attribute class has no constructor (#29955).
     *
     * php-src: object_init_with_constructor() Error — zend_get_attribute_object path.
     */
    public static function emitNoCtorArgsError(Context $context, string $classDisplayName): void
    {
        ErrorRaise::registerDeclarations($context);
        ErrorRaise::ensureLinked($context);
        ErrorRaise::emitRaise(
            $context,
            \PHPCompiler\VM\ReflectionSupport::attributeNoCtorArgsMessage($classDisplayName)
        );
    }

    /** True when ReflectionAttribute::args has at least one entry (argc>0). */
    public static function emitArgsNonEmpty(Context $context, Value $attrObj): Value
    {
        $argsVar = $context->type->object->propertyFetch($attrObj, 'ReflectionAttribute', 'args');
        if (Variable::TYPE_HASHTABLE === $argsVar->type) {
            $argsHt = $argsVar->value;
        } else {
            $argsHt = HashTableHelper::readHashtableFromValueBox($context, $argsVar);
        }
        $n = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $argsHt
        );
        $sizeT = $context->getTypeFromString('size_t');

        return $context->builder->icmp(
            \PHPLLVM\Builder::INT_UGT,
            $n,
            $sizeT->constInt(0, false)
        );
    }
}
