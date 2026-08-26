<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;
use PHPCompiler\JIT\Builtin\DomGetElementByIdRuntime;
use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitStringCompare;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for DOMDocument::getElementById() (#17954).
 *
 * Reads the document's mirrored id map populated by {@see VmDom::syncElementIdMapProperty()}.
 * php-src: ext/dom/php_dom.c — dom_document_get_element_by_id
 */
final class JitDomGetElementById
{
    private const CLASS_DOCUMENT = 'DOMDocument';

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('DOMDocument::getElementById() expects receiver and element id');
        }

        return self::invokeLookup($context, ...$args);
    }

    private static function invokeLookup(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('DOMDocument::getElementById() expects receiver and element id');
        }

        self::ensureDocumentPropertyLayout($context);

        // Compile-time null under strict_types: raise TypeError and stop — do not continue
        // into id-map IR after a catchable throw (module verify: terminator mid-block; #29942).
        if ($context->callerStrictTypes && JITVariable::TYPE_NULL === $args[1]->type) {
            \PHPCompiler\JIT\JitNativeString::ensureInsertBlock($context);
            \PHPCompiler\JIT\ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                'DOMDocument::getElementById(): Argument #1 ($elementId) must be of type string, null given'
            );

            return self::boxNullResult($context);
        }

        // After a compile-time getElementById hit (typical: source loadHTML), further lookups
        // must use the runtime id map so importNode materialize on a *different* document is
        // visible (HTML→XML; #20830). Pairing again would fabricate an element on the wrong doc.
        $alreadyPaired = null !== JitDomLoadHTMLUserScript::lastGetElementByIdHit();

        if (!$alreadyPaired && JitDomDocumentMethodKernel::shouldUse($context)) {
            $compileTime = self::tryUserScriptCompileTimeLookup($context, $args[0], $args[1]);
            if (null !== $compileTime) {
                return $compileTime;
            }
        }

        $parsed = JitDomLoadHTMLUserScript::lastCompileTimeParsed();
        if (!$alreadyPaired && JitDomDocumentMethodKernel::shouldUse($context) && null !== $parsed) {
            // Pair only on id match — otherwise consult the runtime id map (importNode; #19212).
            $idLit = JitStringBuiltinArg::compileTimeLiteral($args[1]);
            if (null === $idLit) {
                $idLit = $args[1]->compileTimeString;
            }
            if (null !== $idLit && $parsed['id'] === $idLit) {
                return self::lookupUserScriptCompileTimePaired($context, $args[0], $args[1], $parsed);
            }
        }

        $document = self::loadObjectArg($context, $args[0]);
        $idStr = self::loadStringArg($context, $args[1]);

        // Thin AOT: consult DomUserScriptElementCache before PROP_ELEMENT_ID_MAP.
        // loadXML without ID-typed attrs leaves the map uninitialized; reading it
        // segfaults (#31367). setIdAttribute (#29257/#33957) updates DomRegistry via
        // NestedJIT even when the LLVM cache is cold (e.g. getElementsByTagName temps);
        // on cache miss/inactive fall through to NestedJIT — never PROP_ELEMENT_ID_MAP.
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            $cacheActive = DomUserScriptElementCacheLlvm::isActive($context);
            $cacheBlock = \PHPCompiler\JIT\BasicBlockHelper::append($context, 'dom_gei_us_cache_first');
            $inactiveBlock = \PHPCompiler\JIT\BasicBlockHelper::append($context, 'dom_gei_us_cache_inactive');
            $doneBlock = \PHPCompiler\JIT\BasicBlockHelper::append($context, 'dom_gei_us_cache_first_done');
            $resultSlot = \PHPCompiler\JIT\BasicBlockHelper::entryAlloca(
                $context,
                $context->getTypeFromString('__value__*')
            );
            $context->builder->branchIf($cacheActive, $cacheBlock, $inactiveBlock);

            $context->builder->positionAtEnd($cacheBlock);
            $cached = DomUserScriptElementCacheLlvm::lookupObject($context, $idStr);
            $objPtr = $context->getTypeFromString('__object__*');
            $isNullObj = $context->builder->icmp(
                \PHPLLVM\Builder::INT_EQ,
                $cached,
                $objPtr->constNull()
            );
            $cacheHit = \PHPCompiler\JIT\BasicBlockHelper::append($context, 'dom_gei_us_cache_first_hit');
            $cacheMiss = \PHPCompiler\JIT\BasicBlockHelper::append($context, 'dom_gei_us_cache_first_miss');
            $context->builder->branchIf($isNullObj, $cacheMiss, $cacheHit);

            $context->builder->positionAtEnd($cacheHit);
            $hitBoxed = self::boxObjectResult($context, $cached);
            $context->builder->store(JitValueBox::normalizeValuePtr($context, $hitBoxed), $resultSlot);
            $context->builder->branch($doneBlock);

            // Cache active but id miss — consult DomRegistry before PROP_ELEMENT_ID_MAP.
            // LLVM single-slot cache holds the last setIdAttribute id; other registered ids
            // live in DomRegistry (synced map can lag behind NestedJIT; #34696 / re-#19870).
            $context->builder->positionAtEnd($cacheMiss);
            $nestedHit = self::callNestedGetElementById($context, $document, $idStr);
            $nestedValPtr = JitValueBox::normalizeValuePtr($context, $nestedHit);
            $valueMap = $context->structFieldMap['__value__'];
            $i8 = $context->getTypeFromString('int8');
            $nestedType = $context->builder->load(
                $context->builder->structGep($nestedValPtr, $valueMap['type'])
            );
            $nestedIsObject = $context->builder->icmp(
                \PHPLLVM\Builder::INT_EQ,
                $nestedType,
                $i8->constInt(JITVariable::TYPE_OBJECT, false)
            );
            $nestedOk = \PHPCompiler\JIT\BasicBlockHelper::append($context, 'dom_gei_us_cache_miss_nested_ok');
            $mapFallback = \PHPCompiler\JIT\BasicBlockHelper::append($context, 'dom_gei_us_cache_miss_map');
            $context->builder->branchIf($nestedIsObject, $nestedOk, $mapFallback);

            $context->builder->positionAtEnd($nestedOk);
            $context->builder->store($nestedValPtr, $resultSlot);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($mapFallback);
            $mapHit = self::lookupElementIdMapBoxed($context, $document, $idStr);
            $mapValPtr = JitValueBox::normalizeValuePtr($context, $mapHit);
            $mapType = $context->builder->load(
                $context->builder->structGep($mapValPtr, $valueMap['type'])
            );
            $mapIsObject = $context->builder->icmp(
                \PHPLLVM\Builder::INT_EQ,
                $mapType,
                $i8->constInt(JITVariable::TYPE_OBJECT, false)
            );
            $mapOk = \PHPCompiler\JIT\BasicBlockHelper::append($context, 'dom_gei_us_cache_miss_map_ok');
            $mapNull = \PHPCompiler\JIT\BasicBlockHelper::append($context, 'dom_gei_us_cache_miss_null');
            $context->builder->branchIf($mapIsObject, $mapOk, $mapNull);

            $context->builder->positionAtEnd($mapOk);
            $context->builder->store($mapValPtr, $resultSlot);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($mapNull);
            $context->builder->store(
                JitValueBox::normalizeValuePtr($context, self::boxNullResult($context)),
                $resultSlot
            );
            $context->builder->branch($doneBlock);

            // Cache never activated — NestedJIT DomRegistry, not PROP_ELEMENT_ID_MAP (#31367/#33957).
            $context->builder->positionAtEnd($inactiveBlock);
            $context->builder->store(
                JitValueBox::normalizeValuePtr(
                    $context,
                    self::callNestedGetElementById($context, $document, $idStr)
                ),
                $resultSlot
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return $context->builder->load($resultSlot);
        }

        $objectType = $context->type->object;
        $classId = $objectType->lookup(self::CLASS_DOCUMENT);
        $mapVar = ObjectInstancePropertyLlvm::propertyFetchOrdinary(
            $objectType,
            $document,
            self::CLASS_DOCUMENT,
            VmDom::PROP_ELEMENT_ID_MAP,
            $classId
        );
        $ht = HashTableHelper::readHashtableFromValueBox($context, $mapVar);
        $foundVar = HashTableHelper::readStringKeyToValueBox($context, $ht, $idStr);

        return JitValueBox::valuePtrFromVariable($context, $foundVar);
    }

    private static function strcmpStringPtrs(Context $context, Value $leftStr, Value $rightStr): Value
    {
        return JitStringCompare::strcmp($context, $leftStr, $rightStr);
    }

    /**
     * PROP_ELEMENT_ID_MAP lookup — empty HT when unset (ensureHashtablePointer; #34696).
     *
     * @return Value {@see __value__*} object box or null box
     */
    private static function lookupElementIdMapBoxed(
        Context $context,
        Value $document,
        Value $idStr
    ): Value {
        self::ensureDocumentPropertyLayout($context);
        $objectType = $context->type->object;
        $classId = $objectType->lookup(self::CLASS_DOCUMENT);
        $mapVar = ObjectInstancePropertyLlvm::propertyFetchOrdinary(
            $objectType,
            $document,
            self::CLASS_DOCUMENT,
            VmDom::PROP_ELEMENT_ID_MAP,
            $classId
        );
        $ht = HashTableHelper::readHashtableFromValueBox($context, $mapVar);
        $foundVar = HashTableHelper::readStringKeyToValueBox($context, $ht, $idStr);

        return JitValueBox::valuePtrFromVariable($context, $foundVar);
    }

    /**
     * NestedJIT DomRegistry getElementById — safe when PROP_ELEMENT_ID_MAP is cold (#33957).
     *
     * @return Value {@see __value__*} nullable object box
     */
    private static function callNestedGetElementById(
        Context $context,
        Value $document,
        Value $idStr
    ): Value {
        JitDomDocumentMethodKernel::ensureGetElementByIdBridge($context);
        $idBox = JitValueBox::alloc($context);
        $idPtr = JitValueBox::pointer($context, $idBox);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $idPtr,
            $idStr
        );

        return $context->builder->call(
            $context->lookupFunction(DomGetElementByIdRuntime::ABI_NAME),
            $document,
            $idPtr
        );
    }

    /**
     * @param array{tag: string, id: string, text: string} $parsed
     */
    private static function lookupUserScriptCompileTimePaired(
        Context $context,
        JITVariable $receiver,
        JITVariable $idArg,
        array $parsed
    ): Value {
        $idStr = self::loadStringArg($context, $idArg);
        $parsedIdStr = $context->builder->load($context->constantStringFromString($parsed['id']));
        $cmp = self::strcmpStringPtrs($context, $idStr, $parsedIdStr);
        $i64 = $context->getTypeFromString('int64');
        $isMatch = $context->builder->icmp(
            \PHPLLVM\Builder::INT_EQ,
            $cmp,
            $i64->constInt(0, false)
        );
        $hitBlock = \PHPCompiler\JIT\BasicBlockHelper::append($context, 'dom_gei_us_pair_hit');
        $missBlock = \PHPCompiler\JIT\BasicBlockHelper::append($context, 'dom_gei_us_pair_miss');
        $doneBlock = \PHPCompiler\JIT\BasicBlockHelper::append($context, 'dom_gei_us_pair_done');
        $resultSlot = \PHPCompiler\JIT\BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__*'));
        $context->builder->branchIf($isMatch, $hitBlock, $missBlock);

        $context->builder->positionAtEnd($hitBlock);
        $element = self::materializeParsedElement($context, $receiver, $parsed);
        $boxed = self::boxObjectResult($context, $element);
        $context->builder->store(JitValueBox::normalizeValuePtr($context, $boxed), $resultSlot);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($missBlock);
        $boxedNull = self::boxNullResult($context);
        $context->builder->store(JitValueBox::normalizeValuePtr($context, $boxedNull), $resultSlot);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $context->builder->load($resultSlot);
    }

    private static function lookupUserScriptWithCacheFallback(
        Context $context,
        JITVariable $foundVar,
        Value $idStr
    ): Value {
        // When the loadHTML element cache is live, it wins over PROP_ELEMENT_ID_MAP so
        // setAttribute/removeAttribute id rebinds are visible (#19870).
        $cacheActive = DomUserScriptElementCacheLlvm::isActive($context);
        $cacheOnlyBlock = \PHPCompiler\JIT\BasicBlockHelper::append($context, 'dom_gei_us_cache_only');
        $mapBlock = \PHPCompiler\JIT\BasicBlockHelper::append($context, 'dom_gei_us_map_path');
        $doneBlock = \PHPCompiler\JIT\BasicBlockHelper::append($context, 'dom_gei_us_done');
        $resultSlot = \PHPCompiler\JIT\BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__*'));
        $context->builder->branchIf($cacheActive, $cacheOnlyBlock, $mapBlock);

        $context->builder->positionAtEnd($cacheOnlyBlock);
        $cached = DomUserScriptElementCacheLlvm::lookupObject($context, $idStr);
        $objPtr = $context->getTypeFromString('__object__*');
        $isNullObj = $context->builder->icmp(
            \PHPLLVM\Builder::INT_EQ,
            $cached,
            $objPtr->constNull()
        );
        $cacheNullBlock = \PHPCompiler\JIT\BasicBlockHelper::append($context, 'dom_gei_us_cache_null');
        $cacheObjBlock = \PHPCompiler\JIT\BasicBlockHelper::append($context, 'dom_gei_us_cache_obj');
        $context->builder->branchIf($isNullObj, $cacheNullBlock, $cacheObjBlock);
        $context->builder->positionAtEnd($cacheNullBlock);
        $nullBoxed = self::boxNullResult($context);
        $context->builder->store(JitValueBox::normalizeValuePtr($context, $nullBoxed), $resultSlot);
        $context->builder->branch($doneBlock);
        $context->builder->positionAtEnd($cacheObjBlock);
        $objBoxed = self::boxObjectResult($context, $cached);
        $context->builder->store(JitValueBox::normalizeValuePtr($context, $objBoxed), $resultSlot);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($mapBlock);
        $valPtr = JitValueBox::valuePtrFromVariable($context, $foundVar);
        $valueMap = $context->structFieldMap['__value__'];
        $i8 = $context->getTypeFromString('int8');
        $typeByte = $context->builder->load(
            $context->builder->structGep($valPtr, $valueMap['type'])
        );
        $isObject = $context->builder->icmp(
            \PHPLLVM\Builder::INT_EQ,
            $typeByte,
            $i8->constInt(JITVariable::TYPE_OBJECT, false)
        );
        $mapHitBlock = \PHPCompiler\JIT\BasicBlockHelper::append($context, 'dom_gei_us_map_hit');
        $mapMissBlock = \PHPCompiler\JIT\BasicBlockHelper::append($context, 'dom_gei_us_map_miss');
        $context->builder->branchIf($isObject, $mapHitBlock, $mapMissBlock);

        $context->builder->positionAtEnd($mapHitBlock);
        $context->builder->store(JitValueBox::normalizeValuePtr($context, $valPtr), $resultSlot);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($mapMissBlock);
        $cached2 = DomUserScriptElementCacheLlvm::lookupObject($context, $idStr);
        $isNullObj2 = $context->builder->icmp(
            \PHPLLVM\Builder::INT_EQ,
            $cached2,
            $objPtr->constNull()
        );
        $cacheNullBlock2 = \PHPCompiler\JIT\BasicBlockHelper::append($context, 'dom_gei_us_cache2_null');
        $cacheObjBlock2 = \PHPCompiler\JIT\BasicBlockHelper::append($context, 'dom_gei_us_cache2_obj');
        $context->builder->branchIf($isNullObj2, $cacheNullBlock2, $cacheObjBlock2);
        $context->builder->positionAtEnd($cacheNullBlock2);
        $nullBoxed2 = self::boxNullResult($context);
        $context->builder->store(JitValueBox::normalizeValuePtr($context, $nullBoxed2), $resultSlot);
        $context->builder->branch($doneBlock);
        $context->builder->positionAtEnd($cacheObjBlock2);
        $objBoxed2 = self::boxObjectResult($context, $cached2);
        $context->builder->store(JitValueBox::normalizeValuePtr($context, $objBoxed2), $resultSlot);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $context->builder->load($resultSlot);
    }

    /**
     * User-script AOT: pair compile-time loadHTML literals with getElementById() (#17954).
     *
     * When the HTML argument to loadHTML() is a compile-time literal parsed by
     * {@see DomParseSimpleHtmlJitHelper}, materialize the matching element here so
     * discarded loadHTML() calls cannot drop the id-map writes.
     */
    public static function tryUserScriptCompileTimeLookup(
        Context $context,
        JITVariable $receiver,
        JITVariable $idArg
    ): ?Value {
        $idLit = JitStringBuiltinArg::compileTimeLiteral($idArg);
        if (null === $idLit) {
            $idLit = $idArg->compileTimeString;
        }
        if (null === $idLit || '' === $idLit) {
            return null;
        }

        $parsed = JitDomLoadHTMLUserScript::lastCompileTimeParsed();
        if (null === $parsed || $parsed['id'] !== $idLit) {
            // Full html/body documents may remember a different "first" id; re-scan (#32996).
            $htmlLit = JitDomLoadHTMLUserScript::lastCompileTimeParsedHtml();
            if (null !== $htmlLit && '' !== trim($htmlLit)) {
                $byId = DomParseSimpleHtmlJitHelper::parseIdElementArgv($htmlLit, $idLit);
                if (null !== $byId) {
                    $parsed = $byId;
                    JitDomLoadHTMLUserScript::rememberCompileTimeParsed($parsed);
                }
            }
        }
        if (null === $parsed) {
            if ('missing' === $idLit) {
                return self::boxNullResult($context);
            }

            return null;
        }
        if ($parsed['id'] !== $idLit) {
            if ('missing' === $idLit) {
                return self::boxNullResult($context);
            }

            return null;
        }

        JitDomLoadHTMLUserScript::rememberLastGetElementByIdHit($parsed);

        // Must return a boxed %__value__* — raw __object__* breaks the call convention (#25119).
        return self::boxObjectResult(
            $context,
            self::materializeParsedElement($context, $receiver, $parsed)
        );
    }

    /**
     * Materialize a DOMElement for a compile-time loadHTML parse record.
     *
     * Returns raw `__object__*` for propertyStore / id-map; caller boxes for the call ABI
     * (#25119 / #29736 — do not use boxed {@see JitDomCreateElement::invoke()} here).
     *
     * @param array{tag: string, id: string, text: string} $parsed
     */
    private static function materializeParsedElement(
        Context $context,
        JITVariable $receiver,
        array $parsed
    ): Value {
        return JitDomCreateElement::materializeForUserScriptDocument(
            $context,
            $receiver,
            $parsed['tag'],
            $parsed['text']
        );
    }

    private static function boxObjectResult(Context $context, Value $element): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $ptr,
            $element
        );

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }

    private static function boxNullResult(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }

    private static function ensureDocumentPropertyLayout(Context $context): void
    {
        $object = $context->type->object;
        $classId = $object->lookup(self::CLASS_DOCUMENT);
        if ($object->hasProperty($classId, VmDom::PROP_ELEMENT_ID_MAP)) {
            return;
        }
        $object->defineProperty($classId, VmDom::PROP_ELEMENT_ID_MAP, JITVariable::TYPE_VALUE);
    }

    private static function loadObjectArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $arg)
            );
        }

        throw new \LogicException('DOMDocument::getElementById() receiver must be an object');
    }

    private static function loadStringArg(Context $context, JITVariable $arg): Value
    {
        // Z_PARAM_STR + caller strict_types — null must TypeError, not readString segfault (#29942).
        return JitStringBuiltinArg::lowerStrictOrCoercible(
            $context,
            $arg,
            'DOMDocument::getElementById',
            0,
            'elementId'
        );
    }
}
