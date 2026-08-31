<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringStrContains;
use PHPCompiler\JIT\Builtin\StringTriggerError;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * O: wire LLVM materialize for unserialize() — split from StringUnserialize (#20785).
 *
 * php-src: ext/standard/var_unserializer.c
 */
final class UnserializeObjectDecodeLlvm
{
    private const OBJECT_HELPER_PATH = '/ext/standard/UnserializeObjectNestedJitHelper.php';

    private const IS_OBJECT_WIRE_HELPER = 'PHPCompiler\\ext\\standard\\UnserializeObjectNestedJitHelper::isObjectWire';

    private const CLASS_NAME_HELPER = 'PHPCompiler\\ext\\standard\\UnserializeObjectNestedJitHelper::className';

    private const PROPS_INTO_HELPER = 'PHPCompiler\\ext\\standard\\UnserializeObjectNestedJitHelper::propsInto';

    private const FIRST_INT_PROP_HELPER = 'PHPCompiler\\ext\\standard\\UnserializeObjectNestedJitHelper::firstIntProp';

    /** @var list<string> */
    private const OBJECT_COMPILED_HELPERS = [
        self::IS_OBJECT_WIRE_HELPER,
        self::CLASS_NAME_HELPER,
        self::PROPS_INTO_HELPER,
        self::FIRST_INT_PROP_HELPER,
    ];

    public static function ensureObjectHelpersCompiled(Context $context): void
    {
        self::ensureNativeHtInternalProxies($context);
        JitVmHelperLink::ensureCompiled(
            $context,
            self::OBJECT_HELPER_PATH,
            self::OBJECT_COMPILED_HELPERS,
            '#27030'
        );
    }

    /** Register phpc_native_ht_* Internal JIT handlers before NestedJIT (#27030 / #24137 / #33636). */
    public static function ensureNativeHtInternalProxies(Context $context): void
    {
        $internals = [
            new \PHPCompiler\ext\standard\phpc_native_ht_alloc(),
            new \PHPCompiler\ext\standard\phpc_native_ht_set_string_key(),
            new \PHPCompiler\ext\standard\phpc_native_ht_set_string_key_long(),
            new \PHPCompiler\ext\standard\phpc_native_ht_set_string_key_double(),
            new \PHPCompiler\ext\standard\phpc_native_ht_set_string_key_bool(),
            new \PHPCompiler\ext\standard\phpc_native_ht_set_string_key_null(),
            new \PHPCompiler\ext\standard\phpc_native_ht_set_string_at(),
            new \PHPCompiler\ext\standard\phpc_native_ht_set_long_at(),
            new \PHPCompiler\ext\standard\phpc_native_ht_set_null_at(),
        ];
        foreach ($internals as $internal) {
            $lc = strtolower($internal->getName());
            $existing = $context->functionProxies[$lc] ?? null;
            if (null === $existing || $existing instanceof \PHPCompiler\JIT\Call\ExternalMethod) {
                $context->functionProxies[$lc] = $internal;
            }
        }
    }

    /**
     * Emit runtime O: decode into the current insert block (call-site class table) (#27030).
     *
     * Returns `__value__*` — object on success, false on parse/class miss (php-src-ish).
     */
    public static function emitObjectDecodeRuntime(Context $context, Value $payloadString): Value
    {
        \PHPCompiler\JIT\Builtin\StringUnserialize::ensureLinked($context);
        self::ensureObjectHelpersCompiled($context);
        self::ensureRuntimeHelpers($context);
        StringStrContains::ensureLinked($context);
        $htPtrTy = $context->getTypeFromString('__hashtable__*');
        $strPtrTy = $context->getTypeFromString('__string__*');
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $i1Ty = $context->getTypeFromString('int1');
        try {
            $context->lookupFunction('__hashtable__readStringKeyValue');
        } catch (\Throwable) {
            $fn = $context->module->addFunction(
                '__hashtable__readStringKeyValue',
                $context->context->functionType($valuePtrTy, false, $htPtrTy, $strPtrTy)
            );
            $context->registerFunction('__hashtable__readStringKeyValue', $fn);
        }
        try {
            $context->lookupFunction('__hashtable__offsetIsSetStringKey');
        } catch (\Throwable) {
            $fn = $context->module->addFunction(
                '__hashtable__offsetIsSetStringKey',
                $context->context->functionType($i1Ty, false, $htPtrTy, $strPtrTy)
            );
            $context->registerFunction('__hashtable__offsetIsSetStringKey', $fn);
        }

        $fn = BasicBlockHelper::parentFunction($context);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $valuePtr = $context->getTypeFromString('__value__*');
        $objPtr = $context->getTypeFromString('__object__*');

        $bbObj = $fn->appendBasicBlock('unser_obj_decode');
        $bbFail = $fn->appendBasicBlock('unser_obj_fail');
        $bbDone = $fn->appendBasicBlock('unser_obj_done');
        $resultSlot = BasicBlockHelper::entryAlloca($context, $valuePtr);

        $payloadArg = JitNestedHelperCoerce::coerceArgForHelper(
            $context,
            $payloadString,
            self::objectHelperFunction($context, self::IS_OBJECT_WIRE_HELPER)->getParam(0)->typeOf()
        );
        $isObj = $context->builder->call(
            self::objectHelperFunction($context, self::IS_OBJECT_WIRE_HELPER),
            $payloadArg
        );
        $isObjI64 = JitNestedHelperCoerce::coerceBridgeResult($context, $isObj, $i64);
        $isObject = $context->builder->icmp(
            \PHPLLVM\Builder::INT_NE,
            $isObjI64,
            $i64->constInt(0, false)
        );
        $context->builder->branchIf($isObject, $bbObj, $bbFail);

        $context->builder->positionAtEnd($bbFail);
        self::emitParseFailureWarning($context, $payloadString);
        $failSlot = \PHPCompiler\JIT\JitValueBox::alloc($context);
        $failPtr = \PHPCompiler\JIT\JitValueBox::pointer($context, $failSlot);
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $failPtr,
            $i32->constInt(0, false)
        );
        $context->builder->store($failPtr, $resultSlot);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbObj);
        $bbPropsOk = $fn->appendBasicBlock('unser_obj_props_ok');
        $context->builder->branch($bbPropsOk);

        $context->builder->positionAtEnd($bbPropsOk);
        /** @var \PHPCompiler\JIT\Builtin\Type\Object_ $object */
        $object = $context->type->object;
        foreach (['DateInterval', 'DateTime', 'DateTimeImmutable', 'DateTimeZone'] as $dateClass) {
            $object->lookup($dateClass);
        }
        $bbMatchFail = $fn->appendBasicBlock('unser_obj_class_miss');
        $bbMatched = $fn->appendBasicBlock('unser_obj_matched');
        $objSlot = BasicBlockHelper::entryAlloca($context, $objPtr);
        $context->builder->store($objPtr->constNull(), $objSlot);
        $firstIntHelper = self::objectHelperFunction($context, self::FIRST_INT_PROP_HELPER);
        $firstIntRaw = $context->builder->call(
            $firstIntHelper,
            JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $payloadString,
                $firstIntHelper->getParam(0)->typeOf()
            )
        );
        $firstInt = JitNestedHelperCoerce::coerceBridgeResult($context, $firstIntRaw, $i64);
        $check = $context->builder->getInsertBlock();
        $hasCase = false;
        foreach ($object->allClassNamesById() as $id => $className) {
            if ('__PHP_Incomplete_Class' === $className) {
                continue;
            }
            $hasCase = true;
            $case = $fn->appendBasicBlock('unser_obj_case_'.$id);
            $next = $fn->appendBasicBlock('unser_obj_try_'.$id);
            $context->builder->positionAtEnd($check);
            $header = 'O:'.\strlen($className).':"'.$className.'":';
            $headerStr = $context->builder->load($context->constantStringFromString($header));
            $isMatch = \PHPCompiler\VM\VmStringCompare::prefixIdentical(
                $context,
                $payloadString,
                $headerStr
            );
            $context->builder->branchIf($isMatch, $case, $next);
            $context->builder->positionAtEnd($case);
            $classLcEarly = strtolower(ltrim($className, '\\'));
            if ('stdclass' === $classLcEarly) {
                foreach (\range('a', 'z') as $ch) {
                    $object->defineProperty($id, (string) $ch, \PHPCompiler\JIT\Variable::TYPE_VALUE);
                }
                foreach (\range('A', 'Z') as $ch) {
                    $object->defineProperty($id, (string) $ch, \PHPCompiler\JIT\Variable::TYPE_VALUE);
                }
                for ($d = 0; $d <= 9; ++$d) {
                    $object->defineProperty($id, (string) $d, \PHPCompiler\JIT\Variable::TYPE_VALUE);
                }
            }
            $objVal = $object->allocate($id);
            BasicBlockHelper::ensureOpenInsertBlock($context, 'unser_obj_after_alloc_'.$id);
            $object->markObjectConstructed($objVal);
            $classLc = strtolower(ltrim($className, '\\'));
            if (\PHPCompiler\VM\ArrayObjectJitHelper::isArrayAsPropsClass($className)) {
                $splName = match ($classLc) {
                    'arrayobject' => 'ArrayObject',
                    'arrayiterator' => 'ArrayIterator',
                    'recursivearrayiterator' => 'RecursiveArrayIterator',
                    default => $className,
                };
                \PHPCompiler\VM\ArrayObjectJitHelper::compileUnserializeRestore(
                    $context,
                    $objVal,
                    $payloadString,
                    $splName
                );
            } elseif ('splfixedarray' === $classLc) {
                \PHPCompiler\VM\SplFixedArrayJitHelper::compileUnserializeRestore(
                    $context,
                    $objVal,
                    $payloadString
                );
            } elseif ('splobjectstorage' === $classLc) {
                \PHPCompiler\VM\SplObjectStorageJitHelper::compileUnserializeRestore(
                    $context,
                    $objVal,
                    $payloadString
                );
            } elseif (
                'spldoublylinkedlist' === $classLc
                || 'splqueue' === $classLc
                || 'splstack' === $classLc
            ) {
                \PHPCompiler\VM\SplDllistJitHelper::compileUnserializeRestore(
                    $context,
                    $objVal,
                    $payloadString
                );
            } elseif ('datetime' === $classLc || 'datetimeimmutable' === $classLc) {
                \PHPCompiler\VM\DateUnserializeJitHelper::compileDateTimeLikeRestore(
                    $context,
                    $objVal,
                    $payloadString,
                    $className
                );
            } elseif ('datetimezone' === $classLc) {
                \PHPCompiler\VM\DateUnserializeJitHelper::compileDateTimeZoneRestore(
                    $context,
                    $objVal,
                    $payloadString
                );
            } elseif ('dateinterval' === $classLc) {
                \PHPCompiler\VM\DateUnserializeJitHelper::compileDateIntervalRestore(
                    $context,
                    $objVal,
                    $payloadString
                );
                $context->lastUnserializeObjectClassUserType = 'DateInterval';
            } elseif ('dateperiod' === $classLc) {
            } else {
                self::ensureObjectHelpersCompiled($context);
                $htI64 = ParseStrNativeOpsJit::alloc($context);
                $propsInto = self::objectHelperFunction($context, self::PROPS_INTO_HELPER);
                $context->builder->call(
                    $propsInto,
                    JitNestedHelperCoerce::coerceArgForHelper(
                        $context,
                        $htI64,
                        $propsInto->getParam(0)->typeOf()
                    ),
                    JitNestedHelperCoerce::coerceArgForHelper(
                        $context,
                        $payloadString,
                        $propsInto->getParam(1)->typeOf()
                    )
                );
                $htVar = new \PHPCompiler\JIT\Variable(
                    $context,
                    \PHPCompiler\JIT\Variable::TYPE_NATIVE_LONG,
                    \PHPCompiler\JIT\Variable::KIND_VALUE,
                    $htI64
                );
                $propsHt = ParseStrNativeOpsJit::htPointerFromI64Arg($context, $htVar);
                $voidPtr = $context->getTypeFromString('void*');
                $i1 = $context->getTypeFromString('int1');
                $serial = 0;
                foreach ($object->instancePropertySets($id) as $propset) {
                    $propName = $propset[1];
                    if ('' === $propName || "\0" === $propName[0] || str_starts_with($propName, '__')) {
                        continue;
                    }
                    ++$serial;
                    $keyStr = $context->builder->load($context->constantStringFromString($propName));
                    $isset = $context->builder->call(
                        $context->lookupFunction('__hashtable__offsetIsSetStringKey'),
                        $propsHt,
                        $keyStr
                    );
                    $yes = BasicBlockHelper::append($context, 'unser_prop_yes_'.$id.'_'.$serial);
                    $no = BasicBlockHelper::append($context, 'unser_prop_no_'.$id.'_'.$serial);
                    $context->builder->branchIf(
                        $context->builder->icmp(
                            \PHPLLVM\Builder::INT_NE,
                            $isset,
                            $i1->constInt(0, false)
                        ),
                        $yes,
                        $no
                    );
                    $context->builder->positionAtEnd($yes);
                    $valEntry = $context->builder->call(
                        $context->lookupFunction('__hashtable__readStringKeyValue'),
                        $propsHt,
                        $keyStr
                    );
                    $slot = $object->propertySlotFor($objVal, $className, $propName);
                    $context->builder->store(
                        $context->builder->pointerCast($valEntry, $voidPtr),
                        $slot
                    );
                    $context->builder->branch($no);
                    $context->builder->positionAtEnd($no);
                }
            }
            BasicBlockHelper::ensureOpenInsertBlock($context, 'unser_obj_after_props_'.$id);
            $context->builder->store($objVal, $objSlot);
            $context->builder->branch($bbMatched);
            $check = $next;
        }
        if (!$hasCase) {
            $context->builder->branch($bbMatchFail);
        } else {
            $context->builder->positionAtEnd($check);
            $context->builder->branch($bbMatchFail);
        }

        $context->builder->positionAtEnd($bbMatchFail);
        $context->builder->branch($bbFail);

        $context->builder->positionAtEnd($bbMatched);
        $objLoaded = $context->builder->load($objSlot);
        $outSlot = \PHPCompiler\JIT\JitValueBox::alloc($context);
        $outPtr = \PHPCompiler\JIT\JitValueBox::pointer($context, $outSlot);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $outPtr,
            $objLoaded
        );
        $context->builder->store($outPtr, $resultSlot);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);

        return $context->builder->load($resultSlot);
    }

    private static function objectHelperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureObjectHelpersCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after UnserializeObjectNestedJitHelper compile (#27030)');
        }

        return $fn;
    }

    private static function ensureRuntimeHelpers(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');

        foreach (
            [
                ['__hashtable__alloc', $htPtr, []],
                ['__string__init', $strPtr, [$i64, $i8p]],
            ] as [$name, $ret, $params]
        ) {
            try {
                $context->lookupFunction($name);
            } catch (\Throwable) {
                $fn = $context->module->addFunction(
                    $name,
                    $context->context->functionType($ret, false, ...$params)
                );
                $context->registerFunction($name, $fn);
            }
        }
    }

    private static function emitParseFailureWarning(Context $context, Value $payloadString): void
    {
        StringTriggerError::ensureLinked($context);
        TypeErrorRaise::ensureDeclInScope($context, 'snprintf', $context->context->functionType(
            $context->getTypeFromString('int32'),
            true,
            $context->getTypeFromString('char*'),
            $context->getTypeFromString('size_t'),
            $context->getTypeFromString('char*')
        ));
        try {
            $context->lookupFunction('__mm__malloc');
        } catch (\Throwable) {
            $context->type->memorymanager->register();
        }
        $strMap = $context->structFieldMap['__string__'];
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $charPtr = $context->getTypeFromString('char*');
        $lenI64 = $context->builder->load(
            $context->builder->structGep($payloadString, $strMap['length'])
        );
        $fn = BasicBlockHelper::parentFunction($context);
        $bbWarn = $fn->appendBasicBlock('unser_fail_warn');
        $bbSkip = $fn->appendBasicBlock('unser_fail_skip');
        $isEmpty = $context->builder->icmp(
            Builder::INT_EQ,
            $lenI64,
            $context->getTypeFromString('int64')->constInt(0, false)
        );
        $context->builder->branchIf($isEmpty, $bbSkip, $bbWarn);
        $context->builder->positionAtEnd($bbWarn);
        $lenI32 = $context->builder->trunc($lenI64, $i32);
        $bufSize = $sizeT->constInt(128, false);
        $buf = $context->builder->call($context->lookupFunction('__mm__malloc'), $bufSize);
        $bufChar = $context->builder->pointerCast($buf, $charPtr);
        $fmt = $context->builder->pointerCast(
            $context->constantFromString('unserialize(): Error at offset %d of %d bytes'),
            $charPtr
        );
        LibcExtern::ensureSnprintf($context);
        $written = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $bufChar,
            $bufSize,
            $fmt,
            $lenI32,
            $lenI32
        );
        $level = \PHPCompiler\CompilerVersion::supportsUnserializeErrorAtOffsetWarning()
            ? \PHPCompiler\VM\ErrorReporter::E_WARNING
            : \PHPCompiler\VM\ErrorReporter::E_NOTICE;
        $msgPtr = $context->builder->pointerCast($bufChar, $i8p);
        $emptyFile = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $context->builder->zExt($written, $sizeT),
            $i32->constInt($level, false),
            $emptyFile,
            $i32->constInt(0, false)
        );
        $context->builder->call($context->lookupFunction('__mm__free'), $buf);
        $context->builder->branch($bbSkip);
        $context->builder->positionAtEnd($bbSkip);
    }
}
