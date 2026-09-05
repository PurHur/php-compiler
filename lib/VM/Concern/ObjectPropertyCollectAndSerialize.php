<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\DateIntervalSupport;
use PHPCompiler\VM\DatePeriodSupport;
use PHPCompiler\VM\DateTimeSupport;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\ScriptExit;
use PHPCompiler\VM\Variable;
use PHPCompiler\VM\WeakRefSupport;

/**
 * Object property collect / debug / serialize / get_object_vars for the VM (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}: {@code getObjectDebugProperties} through
 * {@code isPropertyAccessibleForObjectVars} (Zend var_dump / get_object_vars /
 * serialize property bags). Concern trait — same namespace as parent so relative
 * Frame / VM helpers resolve.
 */
trait ObjectPropertyCollectAndSerialize
{
    /**
     * Properties for var_dump / print_r when __debugInfo is defined (Zend parity, #3259, #29379).
     *
     * Integer HashTable keys stay ints so var_dump prints `[0]=>` not `["0"]=>`
     * (php-src zend_array / php_var_dump; SplFixedArray #19783).
     *
     * @return array<int|string, Variable>
     */
    public function getObjectDebugProperties(ObjectEntry $object, ?Frame $frame = null): array
    {
        // Closure: Zend zend_closure_get_debug_info handler — not a __debugInfo method (#22565).
        if (null !== $object->closureState) {
            $props = [];
            foreach ($object->closureState->debugInfoEntries() as $name => $value) {
                $copy = new Variable();
                $copy->copyFrom($value->resolveIndirect());
                $props[$name] = $copy;
            }

            return $props;
        }
        // WeakMap: Zend zend_weakmap_get_properties_for(DEBUG) — key/value pairs, not storage (#24522).
        if (WeakRefSupport::isWeakMap($object)) {
            return WeakRefSupport::debugInfoEntries($object);
        }
        if ($this->hasInstanceMethod($object->class, '__debuginfo')) {
            // php-src zend_std_get_debug_info: hook throw → zend_exception_error(E_WARNING)
            // then zend_error_noreturn(E_ERROR, "__debuginfo() must return an array") (#25748).
            // Caller try/catch must not absorb the hook exception.
            try {
                $result = $this->invokeInstanceMethod($object, '__debugInfo')->resolveIndirect();
            } catch (ScriptExit $e) {
                throw $e;
            } catch (VM\BuiltinCallbackCatchRedirect $e) {
                throw $e;
            } catch (VM\MagicMethodInvocationAborted $e) {
                throw $e;
            } catch (\Throwable $hookException) {
                $this->raiseDebugInfoMustReturnArrayFatal($frame, $hookException, $object);
            }
            if (Variable::TYPE_NULL === $result->type) {
                return [];
            }
            if (Variable::TYPE_ARRAY !== $result->type) {
                $this->raiseDebugInfoMustReturnArrayFatal($frame, null, $object);
            }
            $props = [];
            foreach ($result->toArray()->iterateKeyed(true) as [$key, $value]) {
                $name = Variable::TYPE_INTEGER === $key->type
                    ? $key->toInt()
                    : $key->toString();
                $copy = new Variable();
                $copy->copyFrom($value->resolveIndirect());
                $props[$name] = $copy;
            }

            return $props;
        }
        // DateInterval: Zend date_interval_get_properties DEBUG wire (#22473).
        // Same bag as get_object_vars / (array) cast (#22446) — never walk raw slots
        // (uninit date_string prototype is TYPE_STRING without $string → Variable::$string Error).
        $intervalMap = $this->dateIntervalObjectVarsPropertyMap($object);
        if (null !== $intervalMap) {
            return $intervalMap;
        }
        // php-src ext/date/php_date.c — date_object_get_properties_for(DEBUG) (#22462).
        // User props first, then Zend date/timezone wire; never leak __dt_* storage.
        $dateWire = DateTimeSupport::tryDebugWirePropertyMap($object, $this->context);
        if (null !== $dateWire) {
            $user = null !== $frame
                ? DateTimeSupport::filterInternalStorageFromMangledVars(
                    $this->collectDebugPropertiesForBuiltin($object, $frame)
                )
                : DateTimeSupport::filterInternalStorageFromMangledVars(
                    $this->rawPropertiesAsDebugMap($object)
                );

            return $user + $dateWire;
        }
        if (null !== $frame) {
            return $this->collectDebugPropertiesForBuiltin($object, $frame);
        }

        return $object->class->getProperties($object->getRawProperties(), ClassEntry::PROP_PURPOSE_DEBUG);
    }

    /**
     * php-src zend_std_get_debug_info failure: optional Warning for hook throw, then E_ERROR (#25748).
     *
     * Warning stack frames match Zend engine-invoke shape (#28618):
     * `[internal function]: Class->__debugInfo()` then `var_dump()`/`print_r()`/…
     *
     * @return never
     */
    private function raiseDebugInfoMustReturnArrayFatal(
        ?Frame $frame,
        ?\Throwable $hookException,
        ObjectEntry $object,
    ): never {
        if (null !== $hookException) {
            VM\ExceptionSupport::emitNativeUncaughtWarning(
                $hookException,
                null,
                $this->context->errors->getDisplayErrors(),
                VM\ExceptionTrace::buildDebugInfoEngineInvokeTrace($object, $frame),
            );
        }
        $message = '__debuginfo() must return an array';
        if (null !== $frame) {
            [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        } else {
            $file = '';
            $line = 0;
            $stack = $this->context->runStackFrames();
            if ([] !== $stack) {
                [$file, $line] = VM\ExceptionSupport::userFatalSite($stack[0]);
            }
        }
        $this->context->errors->recordLastError(
            VM\ErrorReporter::E_ERROR,
            $message,
            $file,
            $line
        );
        VM\ErrorReporter::writeCliErrorOutput(
            VM\ErrorReporter::E_ERROR,
            $message,
            '' !== $file ? $file : null,
            $line,
            $this->context->errors->getDisplayErrors()
        );
        throw new ScriptExit(255);
    }

    /**
     * Raw instance slots as a debug property map (no hooks) — DateTime DEBUG fallback without Frame.
     *
     * @return array<string, Variable>
     */
    private function rawPropertiesAsDebugMap(ObjectEntry $object): array
    {
        /** @var array<string, Variable> $result */
        $result = [];
        foreach ($object->getRawProperties() as $name => $prop) {
            $value = $prop->resolveIndirect();
            if (VM\TypedPropertyCheck::isUninitialized($value)) {
                continue;
            }
            $copy = new Variable();
            $copy->copyFrom($value);
            $result[$name] = $copy;
        }

        return $result;
    }

    /**
     * Lowercase names of separate hook backing fields — hidden from debug/var_export (#8854, zend_property_hooks.c).
     *
     * @return array<string, true>
     */
    private function separatePropertyHookBackingNameSet(ObjectEntry $object): array
    {
        /** @var array<string, true> $set */
        $set = [];
        foreach (array_reverse(\PHPCompiler\ext\standard\VmReflection::classHierarchyChain($object->class, $this->context)) as $class) {
            $lcClass = strtolower($class->name);
            foreach ($this->context->propertyHookRegistry[$lcClass] ?? [] as $hookProp => $meta) {
                if (!is_array($meta)) {
                    continue;
                }
                $backingName = $meta['setBacking'] ?? $meta['getBacking'] ?? null;
                if (null === $backingName || 0 === strcasecmp($backingName, $hookProp)) {
                    continue;
                }
                $set[strtolower($backingName)] = true;
            }
        }

        return $set;
    }

    /**
     * get_mangled_object_vars() — mangled keys, dynamic props, raw backing (#3497, #10491, #22445, #29379).
     *
     * php-src: Zend/zend_builtin_functions.c — ZEND_FUNCTION(get_mangled_object_vars)
     * uses zend_get_properties_no_lazy_init (raw property table), not get-hook reads.
     * DateTime / DateTimeImmutable / DateTimeZone store state in C on Zend — filter
     * compiler __dt_* storage keys (#22445).
     *
     * @return array<string, Variable>
     */
    public function collectMangledObjectVarsForBuiltin(ObjectEntry $object, Frame $frame): array
    {
        // DateInterval: Zend date_interval_get_properties wire (not raw slots / uninit date_string) (#22446).
        $dateMap = $this->dateIntervalObjectVarsPropertyMap($object);
        if (null !== $dateMap) {
            return $dateMap;
        }

        // DateTime / DateTimeImmutable / DateTimeZone: Zend raw property table is empty (#22445).
        return DateTimeSupport::filterInternalStorageFromMangledVars(
            $this->collectDebugPropertiesForBuiltin($object, $frame)
        );
    }

    /**
     * array_walk / array_walk_recursive object property keys — Zend-mangled (#23552).
     *
     * @return list<string>
     */
    public function collectObjectArrayWalkPropertyKeys(ObjectEntry $object, Frame $frame): array
    {
        $ctx = $this->context;
        $keys = [];
        $seenLc = [];
        $seenPrivate = [];
        $seenDeclaredLc = [];
        foreach (array_reverse(\PHPCompiler\ext\standard\VmReflection::classHierarchyChain($object->class, $ctx)) as $class) {
            foreach ($class->properties as $meta) {
                $lc = strtolower($meta->name);
                // Track phpInvisible before skip so raw instance slots are not re-listed (#31439).
                $seenDeclaredLc[$lc] = true;
                if ($meta->phpInvisible) {
                    continue;
                }
                $isPrivate = ($meta->visibility & \PHPCfg\Func::FLAG_PRIVATE) !== 0;
                if ($isPrivate) {
                    $privKey = ($meta->declaringClassLc !== '' ? $meta->declaringClassLc : strtolower($class->name))."\0".$lc;
                    if (isset($seenPrivate[$privKey])) {
                        continue;
                    }
                    $seenPrivate[$privKey] = true;
                } else {
                    if (isset($seenLc[$lc])) {
                        continue;
                    }
                    $seenLc[$lc] = true;
                }
                if (DateTimeSupport::isInternalStorageProperty($meta->name)) {
                    continue;
                }
                if ($meta->propertyHookVirtual && null === $meta->getHookMethodLc) {
                    continue;
                }
                // Presence only — avoid resolveIndirect during key listing (keeps by-ref slots healthy).
                if (!$object->hasPropertyForMeta($meta) && $meta->prototype->hasDeclaredTypeConstraint()) {
                    continue;
                }
                $keys[] = \PHPCompiler\ext\standard\VmReflection::manglePropertyKey($meta, $ctx);
            }
        }
        foreach ($object->getRawProperties() as $name => $_) {
            $nameLc = strtolower($name);
            if (isset($seenDeclaredLc[$nameLc]) || isset($seenLc[$nameLc])) {
                continue;
            }
            if (DateTimeSupport::isInternalStorageProperty((string) $name)) {
                continue;
            }
            $keys[] = (string) $name;
        }

        return $keys;
    }

    /**
     * Foreach property names — same visibility as get_object_vars(), without get-hook side effects (#29702).
     *
     * php-src: ZEND_FE_RESET / get_properties_for(FOREACH) lists slots; FE_FETCH invokes get once
     * per hooked property. Building the key list must not call zend_read_property_ex.
     *
     * @return list<string>
     */
    public function collectObjectForeachPropertyKeys(ObjectEntry $object, Frame $frame): array
    {
        // Enum / DateInterval bags have no get-hook double-read risk; reuse the vars map.
        if (VM\EnumCaseSupport::isEnumCase($object)) {
            return array_keys($this->collectObjectVarsForBuiltin($object, $frame));
        }
        if (null !== $this->dateIntervalObjectVarsPropertyMap($object)) {
            return array_keys($this->collectObjectVarsForBuiltin($object, $frame));
        }

        $ctx = $this->context;
        $scopeFrame = $frame;
        while (null !== $scopeFrame && null !== $scopeFrame->handler) {
            $scopeFrame = $scopeFrame->parent;
        }
        if (null === $scopeFrame) {
            $scopeFrame = $frame;
        }
        $callerClassLc = $this->callerClassLc($scopeFrame);
        if (
            null === $callerClassLc
            && $object->class->isInternal
            && !$object->class->allowsDynamicProperties
            && !$this->internalClassExportsGetObjectVars($object)
        ) {
            return [];
        }

        /** @var list<string> $keys */
        $keys = [];
        /** @var array<string, true> $seenLc */
        $seenLc = [];
        /** @var array<string, true> $seenPrivate */
        $seenPrivate = [];
        /** @var array<string, true> $seenDeclaredLc */
        $seenDeclaredLc = [];
        foreach (array_reverse(\PHPCompiler\ext\standard\VmReflection::classHierarchyChain($object->class, $ctx)) as $class) {
            foreach ($class->properties as $meta) {
                $lc = strtolower($meta->name);
                $isPrivate = ($meta->visibility & \PHPCfg\Func::FLAG_PRIVATE) !== 0;
                $seenDeclaredLc[$lc] = true;
                if ($isPrivate) {
                    $privKey = ($meta->declaringClassLc !== '' ? $meta->declaringClassLc : strtolower($class->name))."\0".$lc;
                    if (isset($seenPrivate[$privKey])) {
                        continue;
                    }
                    $seenPrivate[$privKey] = true;
                    if (isset($seenLc[$lc])) {
                        continue;
                    }
                } elseif (isset($seenLc[$lc])) {
                    continue;
                }
                if (JitMcjitEmbed::isEmbedClassPadProperty($meta->name)) {
                    continue;
                }
                if ($meta->phpInvisible) {
                    if (!$isPrivate) {
                        $seenLc[$lc] = true;
                    }
                    continue;
                }
                if (!$this->isPropertyAccessibleForObjectVars($meta, $callerClassLc)) {
                    continue;
                }
                $seenLc[$lc] = true;
                if ($meta->propertyHookVirtual && null === $meta->getHookMethodLc) {
                    continue;
                }
                if (null !== $meta->getHookMethodLc) {
                    // List only — value fetch via readObjectForeachProperty invokes get once (#29702).
                    $keys[] = $meta->name;

                    continue;
                }
                if (!$object->hasPropertyForMeta($meta)) {
                    if (!$meta->prototype->hasDeclaredTypeConstraint()) {
                        $keys[] = $meta->name;
                    }

                    continue;
                }
                $value = $object->getPropertyForMeta($meta)->resolveIndirect();
                if (
                    Variable::TYPE_UNDEFINED === $value->type
                    && (
                        $meta->prototype->hasDeclaredTypeConstraint()
                        || null !== $meta->default
                        || $meta->hasRuntimeDefaultInit()
                        || !$meta->prototype->isUndefined()
                    )
                ) {
                    continue;
                }
                if (VM\TypedPropertyCheck::isUninitialized($value)) {
                    if ($meta->prototype->hasDeclaredTypeConstraint()) {
                        continue;
                    }
                }
                $keys[] = $meta->name;
            }
        }
        foreach ($object->getRawProperties() as $name => $prop) {
            $nameLc = strtolower($name);
            if (isset($seenDeclaredLc[$nameLc]) || isset($seenLc[$nameLc])) {
                continue;
            }
            if (JitMcjitEmbed::isEmbedClassPadProperty($name)) {
                continue;
            }
            if (DateTimeSupport::isInternalStorageProperty((string) $name)) {
                continue;
            }
            $value = $prop->resolveIndirect();
            if (VM\TypedPropertyCheck::omitFromPropertyEnumeration($value)) {
                continue;
            }
            $keys[] = (string) $name;
        }

        return $keys;
    }

    /**
     * var_dump()/print_r()/debug_zval_dump() property list — mangled keys, no get hooks (#29379).
     *
     * php-src: zend_get_properties_for(..., ZEND_PROP_PURPOSE_DEBUG) walks the property table
     * without zend_read_property_ex — virtual hooked props are omitted; backed hooks dump the
     * backing slot (re-#6604 wrongly invoked get). var_export / get_object_vars still use get.
     *
     * @return array<string, Variable>
     */
    private function collectDebugPropertiesForBuiltin(ObjectEntry $object, Frame $frame): array
    {
        $ctx = $this->context;
        $hookBackingLc = $this->separatePropertyHookBackingNameSet($object);
        /** @var array<string, Variable> $result */
        $result = [];
        /** @var array<string, true> $seenLc */
        $seenLc = [];
        /** @var array<string, true> $seenPrivate */
        $seenPrivate = [];
        /** @var array<string, true> $seenDeclaredLc — skip raw re-add of declared slots (#22521) */
        $seenDeclaredLc = [];
        foreach (array_reverse(\PHPCompiler\ext\standard\VmReflection::classHierarchyChain($object->class, $ctx)) as $class) {
            foreach ($class->properties as $meta) {
                $lc = strtolower($meta->name);
                $isPrivate = ($meta->visibility & \PHPCfg\Func::FLAG_PRIVATE) !== 0;
                $seenDeclaredLc[$lc] = true;
                if ($isPrivate) {
                    $privKey = ($meta->declaringClassLc !== '' ? $meta->declaringClassLc : strtolower($class->name))."\0".$lc;
                    if (isset($seenPrivate[$privKey]) || isset($hookBackingLc[$lc])) {
                        continue;
                    }
                    $seenPrivate[$privKey] = true;
                } else {
                    if (isset($seenLc[$lc]) || isset($hookBackingLc[$lc])) {
                        continue;
                    }
                    $seenLc[$lc] = true;
                }
                if ($meta->phpInvisible) {
                    continue;
                }
                // Virtual hooked properties: no DEBUG slot — omit entirely (#29379, zend_property_hooks.c).
                if ($meta->propertyHookVirtual) {
                    continue;
                }
                // Backed hooked property: dump raw backing, never invoke get (#29379).
                if (null !== $meta->getHookMethodLc || null !== $meta->setHookMethodLc) {
                    if (!$object->hasPropertyForMeta($meta)) {
                        // Typed hooked slot still absent → var_dump shows uninitialized(T) (#31147).
                        if ($meta->prototype->hasDeclaredTypeConstraint() || $meta->prototype->isUndefined()) {
                            $key = \PHPCompiler\ext\standard\VmReflection::manglePropertyKey($meta, $ctx);
                            $result[$key] = $this->copyUninitializedDebugPropertySlot(
                                $meta->prototype,
                                $object,
                                $meta->name
                            );
                        }

                        continue;
                    }
                    $value = $object->getPropertyForMeta($meta)->resolveIndirect();
                    $key = \PHPCompiler\ext\standard\VmReflection::manglePropertyKey($meta, $ctx);
                    if (VM\TypedPropertyCheck::isUninitialized($value)) {
                        $result[$key] = $this->copyUninitializedDebugPropertySlot($value, $object, $meta->name);
                        $copy = $result[$key];
                        if (null === $copy->declaredTypeLabel && null !== $meta->prototype->declaredTypeLabel) {
                            $copy->declaredTypeLabel = $meta->prototype->declaredTypeLabel;
                        }
                    } else {
                        $copy = new Variable();
                        $copy->copyFrom($value);
                        $result[$key] = $copy;
                    }

                    continue;
                }
                if (!$object->hasPropertyForMeta($meta)) {
                    $key = \PHPCompiler\ext\standard\VmReflection::manglePropertyKey($meta, $ctx);
                    if ($meta->prototype->hasDeclaredTypeConstraint() || $meta->prototype->isUndefined()) {
                        // Absent typed slot: Zend still dumps uninitialized(T) (#31147).
                        $result[$key] = $this->copyUninitializedDebugPropertySlot(
                            $meta->prototype,
                            $object,
                            $meta->name
                        );
                    } else {
                        $copy = new Variable();
                        $copy->null();
                        $result[$key] = $copy;
                    }

                    continue;
                }
                $value = $object->getPropertyForMeta($meta)->resolveIndirect();
                if (VM\TypedPropertyCheck::isUninitialized($value)) {
                    $key = \PHPCompiler\ext\standard\VmReflection::manglePropertyKey($meta, $ctx);
                    if ($meta->prototype->hasDeclaredTypeConstraint() || $meta->prototype->isUndefined()) {
                        // Include uninitialized typed slots for var_dump / debug_zval_dump (#31147).
                        // print_r skips them in VmPrintR; object header count excludes them.
                        // Must not use Variable::copyFrom — it assertReadable()s (#31147).
                        $copy = $this->copyUninitializedDebugPropertySlot($value, $object, $meta->name);
                        if (null === $copy->declaredTypeLabel && null !== $meta->prototype->declaredTypeLabel) {
                            $copy->declaredTypeLabel = $meta->prototype->declaredTypeLabel;
                        }
                        if (null === $copy->typeConstraint && null !== $meta->prototype->typeConstraint) {
                            $copy->typeConstraint = $meta->prototype->typeConstraint;
                        }
                        $result[$key] = $copy;
                    } else {
                        $copy = new Variable();
                        $copy->null();
                        $result[$key] = $copy;
                    }

                    continue;
                }
                $key = \PHPCompiler\ext\standard\VmReflection::manglePropertyKey($meta, $ctx);
                $copy = new Variable();
                $copy->copyFrom($value);
                $result[$key] = $copy;
            }
        }
        foreach ($object->getRawProperties() as $name => $prop) {
            $nameLc = strtolower($name);
            if (isset($seenDeclaredLc[$nameLc]) || isset($seenLc[$nameLc]) || isset($hookBackingLc[$nameLc])) {
                continue;
            }
            $value = $prop->resolveIndirect();
            if (VM\TypedPropertyCheck::isUninitialized($value)) {
                continue;
            }
            $copy = new Variable();
            $copy->copyFrom($value);
            $result[$name] = $copy;
        }

        return $result;
    }

    /**
     * DEBUG property bag entry for an uninitialized typed slot — never assertReadable (#31147).
     */
    private function copyUninitializedDebugPropertySlot(
        Variable $source,
        ObjectEntry $object,
        string $propertyName
    ): Variable {
        $copy = new Variable();
        $copy->copyUninitializedStaticPropertySlot($source);
        $copy->objectPropertyOwner = $object;
        $copy->objectPropertyName = $propertyName;
        if (null === $copy->declaredTypeLabel && Variable::TYPE_UNDEFINED === $source->resolveIndirect()->type
            && !$source->resolveIndirect()->hasDeclaredTypeConstraint()) {
            // Explicit mixed: typed UNDEFINED without label/constraint.
            $copy->declaredTypeLabel = 'mixed';
        }

        return $copy;
    }

    /**
     * Declared + dynamic properties for get_object_vars() get-hook reads (#5203, #6453).
     *
     * php-src: zend_hooked_object_build_properties + zend_read_property_ex
     *
     * @return array<string, Variable>
     */
    public function collectObjectVarsForBuiltin(ObjectEntry $object, Frame $frame): array
    {
        // Enum cases: Zend zend_enum.c name/value pseudo-properties (foreach + get_object_vars; #23433).
        if (VM\EnumCaseSupport::isEnumCase($object)) {
            $caseVar = new Variable(Variable::TYPE_OBJECT);
            $caseVar->object($object);

            return VM\EnumCaseSupport::objectVarsForCaseVariable($caseVar);
        }
        // DateInterval: Zend date_interval_get_properties — public wire despite isInternal (#22446).
        $dateMap = $this->dateIntervalObjectVarsPropertyMap($object);
        if (null !== $dateMap) {
            return $dateMap;
        }

        // DateTime* / DateTimeZone: Zend property table has no __dt_* storage (#23432, #22445).
        // Base internal CE short-circuits empty above; subclasses still declare inherited slots.
        return DateTimeSupport::filterInternalStorageFromMangledVars(
            $this->collectObjectPropertiesForBuiltin($object, $frame, false)
        );
    }

    /**
     * php-src ext/date/php_date.c — date_interval_get_properties for get_object_vars / mangled / DEBUG (#22446, #22473).
     *
     * Reuses the same Zend wire as var_export / (array) cast ({@see DateIntervalSupport::varExportPropertyMap}).
     * DateTime* stay empty from global scope (#10719); only DateInterval exposes this bag.
     *
     * @return array<string, Variable>|null
     */
    private function dateIntervalObjectVarsPropertyMap(ObjectEntry $object): ?array
    {
        if (DateIntervalSupport::CLASS_DATEINTERVAL !== strtolower($object->class->name)) {
            return null;
        }

        return DateIntervalSupport::varExportPropertyMap($object);
    }

    /**
     * Internal classes that still publish PHP-visible CE properties via get_object_vars
     * (php-src reflection_object handlers; #22515). DateTime* and similar stay empty.
     */
    private function internalClassExportsGetObjectVars(ObjectEntry $object): bool
    {
        $lc = strtolower($object->class->name);
        return match ($lc) {
            VM\ReflectionSupport::REFLECTION_CLASS,
            VM\ReflectionSupport::REFLECTION_OBJECT,
            VM\ReflectionSupport::REFLECTION_METHOD => true,
            default => false,
        };
    }

    /**
     * All set instance properties for var_export() — ignores caller visibility (#3594).
     *
     * php-src: zend_get_properties_for(..., ZEND_PROP_PURPOSE_VAR_EXPORT)
     *
     * @return array<string, Variable>
     */
    public function collectVarExportPropertiesForBuiltin(ObjectEntry $object, Frame $frame): array
    {
        $lc = strtolower($object->class->name);
        if (DateTimeSupport::CLASS_DATETIME === $lc || DateTimeSupport::CLASS_DATETIMEIMMUTABLE === $lc) {
            return DateTimeSupport::varExportPropertyMap($object);
        }
        if (DateTimeSupport::CLASS_DATETIMEZONE === $lc) {
            return DateTimeSupport::varExportTimezonePropertyMap($object);
        }
        if (DateIntervalSupport::CLASS_DATEINTERVAL === $lc) {
            return DateIntervalSupport::varExportPropertyMap($object);
        }
        if (DatePeriodSupport::CLASS_DATEPERIOD === $lc) {
            return DatePeriodSupport::varExportPropertyMap($object);
        }
        // Zend zend_exceptions.c — SensitiveParameterValue get_properties_for(VAR_EXPORT) is empty (#23042).
        if (VM\SensitiveParamSupport::CLASS_NAME === $object->class->name
            || strtolower(VM\SensitiveParamSupport::CLASS_NAME) === $lc) {
            return [];
        }
        // Zend zend_weakrefs.c — WeakMap get_properties_for(VAR_EXPORT) returns NULL (#24522).
        if (WeakRefSupport::isWeakMap($object)) {
            return [];
        }

        return $this->collectObjectPropertiesForBuiltin($object, $frame, true);
    }

    /**
     * @return array<string, Variable>
     */
    private function collectObjectPropertiesForBuiltin(ObjectEntry $object, Frame $frame, bool $forVarExport): array
    {
        $ctx = $this->context;
        $scopeFrame = $frame;
        while (null !== $scopeFrame && null !== $scopeFrame->handler) {
            $scopeFrame = $scopeFrame->parent;
        }
        if (null === $scopeFrame) {
            $scopeFrame = $frame;
        }
        $callerClassLc = $forVarExport ? null : $this->callerClassLc($scopeFrame);
        if (
            !$forVarExport
            && null === $callerClassLc
            && $object->class->isInternal
            && !$object->class->allowsDynamicProperties
            && !$this->internalClassExportsGetObjectVars($object)
        ) {
            return [];
        }
        $hookBackingLc = $forVarExport ? $this->separatePropertyHookBackingNameSet($object) : [];
        /** @var array<string, Variable> $result */
        $result = [];
        /** @var array<string, true> $seenLc — unmangled result keys already taken (first-wins; #22547) */
        $seenLc = [];
        /** @var array<string, true> $seenPrivate — declaring-class private slots (#22521 / #22547) */
        $seenPrivate = [];
        /** @var array<string, true> $seenDeclaredLc — skip raw re-add of declared slots */
        $seenDeclaredLc = [];
        foreach (array_reverse(\PHPCompiler\ext\standard\VmReflection::classHierarchyChain($object->class, $ctx)) as $class) {
            foreach ($class->properties as $meta) {
                $lc = strtolower($meta->name);
                $isPrivate = ($meta->visibility & \PHPCfg\Func::FLAG_PRIVATE) !== 0;
                $seenDeclaredLc[$lc] = true;
                if ($isPrivate) {
                    $privKey = ($meta->declaringClassLc !== '' ? $meta->declaringClassLc : strtolower($class->name))."\0".$lc;
                    if (isset($seenPrivate[$privKey]) || isset($hookBackingLc[$lc])) {
                        continue;
                    }
                    $seenPrivate[$privKey] = true;
                    // Parent private must not claim the result key when inaccessible — child
                    // private/public with the same name may still be visible (#22547).
                    if (isset($seenLc[$lc])) {
                        continue;
                    }
                } elseif (isset($seenLc[$lc]) || isset($hookBackingLc[$lc])) {
                    continue;
                }
                if (JitMcjitEmbed::isEmbedClassPadProperty($meta->name)) {
                    continue;
                }
                if ($meta->phpInvisible) {
                    if (!$isPrivate) {
                        $seenLc[$lc] = true;
                    }
                    continue;
                }
                if (!$forVarExport && !$this->isPropertyAccessibleForObjectVars($meta, $callerClassLc)) {
                    continue;
                }
                // Accessible (or var_export): claim unmangled key — first-wins vs later same name.
                $seenLc[$lc] = true;
                if ($meta->propertyHookVirtual && null === $meta->getHookMethodLc) {
                    continue;
                }
                if (null !== $meta->getHookMethodLc) {
                    $hookValue = $this->fetchPropertyWithHooks($object, $meta->name, $scopeFrame);
                    if (null === $hookValue) {
                        continue;
                    }
                    $value = $hookValue->resolveIndirect();
                    if ($forVarExport) {
                        if (VM\TypedPropertyCheck::omitFromPropertyEnumeration($value)) {
                            continue;
                        }
                    } elseif (VM\TypedPropertyCheck::isUninitialized($value)) {
                        continue;
                    }
                    $copy = new Variable();
                    $copy->copyFrom($value);
                    $result[$meta->name] = $copy;

                    continue;
                }
                if (!$object->hasPropertyForMeta($meta)) {
                    if (!$forVarExport && !$meta->prototype->hasDeclaredTypeConstraint()) {
                        $copy = new Variable();
                        $copy->null();
                        $result[$meta->name] = $copy;
                    }

                    continue;
                }
                $value = $object->getPropertyForMeta($meta)->resolveIndirect();
                if ($forVarExport) {
                    if (VM\TypedPropertyCheck::omitFromPropertyEnumeration($value)) {
                        continue;
                    }
                } elseif (
                    Variable::TYPE_UNDEFINED === $value->type
                    && (
                        $meta->prototype->hasDeclaredTypeConstraint()
                        || null !== $meta->default
                        || $meta->hasRuntimeDefaultInit()
                        || !$meta->prototype->isUndefined()
                    )
                ) {
                    // unset($obj->prop) — omit; never-set untyped falls through to null below (#1370).
                    continue;
                } elseif (VM\TypedPropertyCheck::isUninitialized($value)) {
                    if ($meta->prototype->hasDeclaredTypeConstraint()) {
                        continue;
                    }
                    $copy = new Variable();
                    $copy->null();
                    $result[$meta->name] = $copy;

                    continue;
                }
                $copy = new Variable();
                $copy->copyFrom($value);
                $result[$meta->name] = $copy;
            }
        }
        foreach ($object->getRawProperties() as $name => $prop) {
            $nameLc = strtolower($name);
            if (isset($seenDeclaredLc[$nameLc]) || isset($seenLc[$nameLc]) || isset($hookBackingLc[$nameLc])) {
                continue;
            }
            if (JitMcjitEmbed::isEmbedClassPadProperty($name)) {
                continue;
            }
            $value = $prop->resolveIndirect();
            if (VM\TypedPropertyCheck::omitFromPropertyEnumeration($value)) {
                continue;
            }
            $copy = new Variable();
            $copy->copyFrom($value);
            $result[$name] = $copy;
        }

        return $result;
    }

    public function collectPublicPropertiesForSerialize(ObjectEntry $object, Frame $frame): array
    {
        if (VM\SplArraySupport::hasState($object)) {
            return VM\SplArraySupport::collectJsonEncodeProperties($object);
        }
        $ctx = $this->context;
        $hookFrame = $this->resolvePropertyHookParentFrame($frame);
        /** @var array<string, Variable> $result */
        $result = [];
        /** @var array<string, true> $seenLc */
        $seenLc = [];
        foreach (array_reverse(ext\standard\VmReflection::classHierarchyChain($object->class, $ctx)) as $class) {
            foreach ($class->properties as $meta) {
                $lc = strtolower($meta->name);
                if (isset($seenLc[$lc])) {
                    continue;
                }
                $seenLc[$lc] = true;
                if (!MethodVisibility::isPublic($meta->visibility)) {
                    continue;
                }
                if ($meta->propertyHookVirtual && null === $meta->getHookMethodLc) {
                    continue;
                }
                if (null !== $meta->getHookMethodLc) {
                    $hookValue = $this->fetchPropertyWithHooks($object, $meta->name, $hookFrame);
                    if (null === $hookValue) {
                        continue;
                    }
                    $value = $hookValue->resolveIndirect();
                    if (VM\TypedPropertyCheck::omitFromSerialize($value)) {
                        continue;
                    }
                    $copy = new Variable();
                    $copy->copyFrom($value);
                    $result[$meta->name] = $copy;

                    continue;
                }
                if (!$object->hasProperty($meta->name)) {
                    continue;
                }
                $value = $object->getProperty($meta->name)->resolveIndirect();
                if (VM\TypedPropertyCheck::omitFromSerialize($value)) {
                    continue;
                }
                $copy = new Variable();
                $copy->copyFrom($value);
                $result[$meta->name] = $copy;
            }
        }
        foreach ($object->getRawProperties() as $name => $prop) {
            if (isset($seenLc[strtolower($name)])) {
                continue;
            }
            $value = $prop->resolveIndirect();
            if (VM\TypedPropertyCheck::omitFromSerialize($value)) {
                continue;
            }
            $copy = new Variable();
            $copy->copyFrom($value);
            $result[$name] = $copy;
        }

        return $result;
    }

    /**
     * All declared + dynamic properties for plain-object serialize() — mangled visibility keys (#15751, var.c).
     *
     * php-src: serialize uses raw property-table values (ZEND_PROP_PURPOSE_SERIALIZE), not get hooks.
     * Virtual hooked props have no slot and are omitted; backed hooks serialize the backing field
     * under its mangled name (#28184, re-#6474 — #6474 wrongly matched json_encode get-hook semantics).
     *
     * @return array<string, Variable>
     */
    public function collectObjectPropertiesForSerialize(ObjectEntry $object, Frame $frame): array
    {
        if (VM\SplArraySupport::hasState($object)) {
            return VM\SplArraySupport::collectJsonEncodeProperties($object);
        }
        $ctx = $this->context;
        /** @var array<string, Variable> $result */
        $result = [];
        /** @var array<string, true> $seenLc */
        $seenLc = [];
        /** @var array<string, true> $seenPrivate */
        $seenPrivate = [];
        /** @var array<string, true> $seenDeclaredLc */
        $seenDeclaredLc = [];
        foreach (array_reverse(ext\standard\VmReflection::classHierarchyChain($object->class, $ctx)) as $class) {
            foreach ($class->properties as $meta) {
                $lc = strtolower($meta->name);
                $isPrivate = ($meta->visibility & \PHPCfg\Func::FLAG_PRIVATE) !== 0;
                $seenDeclaredLc[$lc] = true;
                if ($isPrivate) {
                    $privKey = ($meta->declaringClassLc !== '' ? $meta->declaringClassLc : strtolower($class->name))."\0".$lc;
                    if (isset($seenPrivate[$privKey])) {
                        continue;
                    }
                    $seenPrivate[$privKey] = true;
                } else {
                    if (isset($seenLc[$lc])) {
                        continue;
                    }
                    $seenLc[$lc] = true;
                }
                // Virtual hooked properties: no backing store — omit from serialize (#28184).
                if ($meta->propertyHookVirtual) {
                    continue;
                }
                // Non-virtual hooked property: serialize raw backing slot, never invoke get (#28184).
                if (null !== $meta->getHookMethodLc || null !== $meta->setHookMethodLc) {
                    if (!$object->hasPropertyForMeta($meta)) {
                        continue;
                    }
                    $value = $object->getPropertyForMeta($meta)->resolveIndirect();
                    if (VM\TypedPropertyCheck::omitFromSerialize($value)) {
                        continue;
                    }
                    $copy = new Variable();
                    $copy->copyFrom($value);
                    $result[ext\standard\VmReflection::manglePropertyKey($meta, $ctx)] = $copy;

                    continue;
                }
                if (!$object->hasPropertyForMeta($meta)) {
                    continue;
                }
                $value = $object->getPropertyForMeta($meta)->resolveIndirect();
                if (VM\TypedPropertyCheck::omitFromSerialize($value)) {
                    continue;
                }
                $copy = new Variable();
                $copy->copyFrom($value);
                $result[ext\standard\VmReflection::manglePropertyKey($meta, $ctx)] = $copy;
            }
        }
        foreach ($object->getRawProperties() as $name => $prop) {
            $nameLc = strtolower($name);
            if (isset($seenDeclaredLc[$nameLc]) || isset($seenLc[$nameLc])) {
                continue;
            }
            $value = $prop->resolveIndirect();
            if (VM\TypedPropertyCheck::omitFromSerialize($value)) {
                continue;
            }
            $copy = new Variable();
            $copy->copyFrom($value);
            $result[$name] = $copy;
        }

        return $result;
    }

    /**
     * unserialize() property restore — set hooks when declared (#6474, var_unserializer.c).
     *
     * Wire keys are ZEND_PROP_PURPOSE_SERIALIZE mangled names (`\0*\0message`); resolve to the
     * declared slot so typed protected/private props initialize (#26673).
     */
    public function assignUnserializeProperty(
        ObjectEntry $object,
        string $propName,
        Variable $value,
        ?Frame $frame = null
    ): void {
        $meta = VM\PropertyMangle::findPropertyForSerializeKey($object, $propName, $this->context->classes);
        $storageName = null !== $meta ? $meta->name : $propName;
        if ($this->assignHookedPropertyBackingStorage($object, $storageName, $value)) {
            return;
        }
        if (null !== $frame) {
            $hookFrame = $this->resolvePropertyHookParentFrame($frame);
            $writeLvalue = new Variable();
            $writeLvalue->objectPropertyOwner = $object;
            $writeLvalue->objectPropertyName = $storageName;
            if ($this->dispatchPropertySetHookAssign($writeLvalue, $value, $hookFrame)) {
                return;
            }
        }
        if (null !== $meta) {
            $object->getPropertyForMeta($meta)->copyFrom($value->resolveIndirect());

            return;
        }
        $prop = $object->hasProperty($propName)
            ? $object->getProperty($propName)
            : $object->allocateProperty($propName);
        $prop->copyFrom($value);
    }

    /**
     * unserialize() restore when set-hook dispatch is unavailable — write registry backing (#6474).
     */
    private function assignHookedPropertyBackingStorage(
        ObjectEntry $object,
        string $propName,
        Variable $value
    ): bool {
        $lcClass = strtolower($object->class->name);
        $propMeta = $this->context->propertyHookRegistry[$lcClass][$propName]
            ?? $this->context->propertyHookRegistry[$lcClass][strtolower($propName)]
            ?? null;
        if (!is_array($propMeta)) {
            return false;
        }
        $backingName = $propMeta['setBacking'] ?? $propMeta['getBacking'] ?? null;
        if (null === $backingName) {
            return false;
        }
        if (!$object->hasProperty($backingName)) {
            $object->allocateProperty($backingName);
        }
        $object->getProperty($backingName)->copyFrom($value->resolveIndirect());

        return true;
    }

    private function isPropertyAccessibleForObjectVars(VM\ClassProperty $meta, ?string $callerClassLc): bool
    {
        if (MethodVisibility::isPublic($meta->visibility)) {
            return true;
        }
        if (null === $callerClassLc) {
            return false;
        }
        if (($meta->visibility & \PHPCfg\Func::FLAG_PRIVATE) !== 0) {
            return $callerClassLc === $meta->declaringClassLc;
        }
        if (($meta->visibility & \PHPCfg\Func::FLAG_PROTECTED) !== 0) {
            if ($callerClassLc === $meta->declaringClassLc) {
                return true;
            }

            return $this->isClassSameOrSubclassOf($callerClassLc, $meta->declaringClassLc);
        }

        return true;
    }
}
