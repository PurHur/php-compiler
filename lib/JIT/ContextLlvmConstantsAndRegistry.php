<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Config;
use PHPCompiler\VM\Variable as VMVariable;
use PHPLLVM;

/**
 * LLVM function/type registry + compile-time constant materialization for {@see Context} (#36387).
 *
 * Extracted from {@see Context} so lookupFunction / registerType / namedStructType /
 * constantFrom* / constantArrayFromVmHashTable / constantObjectFromVm stay a separate TU
 * from the Context construction / compile / init-emit hubs (split-TU / size-budget ratchet).
 *
 * Used via {@code use ContextLlvmConstantsAndRegistry;} on {@see Context}.
 *
 * No new C ABI. php-src analogy: zend_hash / zend_constants materialization and function_table
 * registration live beside the executor (Zend/zend_compile.c, Zend/zend_constants.c,
 * Zend/zend_execute_API.c) rather than inside request startup.
 */
trait ContextLlvmConstantsAndRegistry
{
    /** @var array<int, PHPLLVM\Value> */
    private array $boolValues = [];

    public function lookupFunction(string $name): PHPLLVM\Value\Function_ {
        // Lazy __value__writeDouble — Value::implement no longer eager-implements (#36141 /
        // peer #36135 writeLong). Thin hello-world must not emit double-box LLVM during init.
        if ('__value__writeDouble' === $name) {
            Builtin\ValueBoxWriteDoubleJit::ensureLinked($this);
        }
        // Lazy __value__writeLong — Value::implement no longer eager-implements (#36135 /
        // peer #36124 writeNull). Thin hello-world must not emit long-box LLVM during init.
        if ('__value__writeLong' === $name) {
            Builtin\ValueBoxWriteLongJit::ensureLinked($this);
        }
        // Lazy __value__writeNull — Value::implement no longer eager-implements (#36124 /
        // peer #36108 writeBool). Thin hello-world must not emit null-box LLVM during init.
        if ('__value__writeNull' === $name) {
            Builtin\ValueBoxWriteNullJit::ensureLinked($this);
        }
        // Lazy __value__writeBool — Value::implement no longer eager-implements (#36108 /
        // peer #36100 malloc). Thin hello-world must not emit bool-box LLVM during init.
        if ('__value__writeBool' === $name) {
            Builtin\ValueBoxWriteBoolJit::ensureLinked($this);
        }
        // Lazy __value__copy — one outlined type-switch per module (#36193).
        if ('__value__copy' === $name) {
            Builtin\ValueBoxCopyJit::ensureLinked($this);
        }
        if (isset($this->functionScope[$name])) {
            return $this->functionScope[$name];
        }
        // After edit-scaffold / bitcode restore, prefer live module over stale scope (#36387).
        $fromModule = $this->module->getNamedFunction($name);
        if ($fromModule instanceof PHPLLVM\Value\Function_) {
            try {
                // php-llvm may wrap a null LLVMValueRef; probe before trusting it.
                $fromModule->countParams();
                $this->functionScope[$name] = $fromModule;

                return $fromModule;
            } catch (\Throwable $e) {
                // fall through to lazy ensure / throw
            }
        }
        // Lazy libc exit(3)/abort(3) — Type::register no longer always-on ensures (#35428 /
        // leftover #33267 / peer #35392). ~292 call sites lookup without a nearby ensure.
        // Lazy setlocale(3) — LocaleStartupRuntime ensures before use (#36074 / #30789).
        // Lazy malloc/realloc/free — MemoryManager\Native::implement + NestedJIT leaves
        // (#36100 / peer #32273); register() no longer eager-ensures the family.
        if ('exit' === $name || 'abort' === $name) {
            LibcExtern::ensureExitAbort($this);
            if (isset($this->functionScope[$name])) {
                return $this->functionScope[$name];
            }
        }
        if ('setlocale' === $name) {
            LibcExtern::ensureSetlocaleDecl($this);
            if (isset($this->functionScope[$name])) {
                return $this->functionScope[$name];
            }
        }
        if ('malloc' === $name || 'realloc' === $name || 'free' === $name) {
            LibcExtern::ensureMallocFamily($this);
            if (isset($this->functionScope[$name])) {
                return $this->functionScope[$name];
            }
        }
        // Lazy stream I/O ABI — Type::register no longer always-on ensures (#33055).
        // SPINE_CHUNK standard hub (VmFs slice) lowers fread before StreamIo::ensureLinked
        // (#36155 Phase B/C); JitFread::invoke lookupFunction must self-ensure.
        if (Builtin\StreamIoRuntime::isLazyLookupRuntimeFunction($name)) {
            Builtin\StreamIoRuntime::ensureLinkedForUserScriptLowering($this);
            if (isset($this->functionScope[$name])) {
                return $this->functionScope[$name];
            }
        }
        throw new \LogicException('Unable to lookup non-existing function ' . $name);
    }

    /** Scope probe for LibcExtern ensure* without re-entering lookupFunction (#35428). */
    public function tryGetRegisteredFunction(string $name): ?PHPLLVM\Value\Function_ {
        return $this->functionScope[$name] ?? null;
    }

    public function registerFunction(string $name, PHPLLVM\Value\Function_ $func): void {
        $this->functionScope[$name] = $func;
    }

    public function registerType(string $name, PHPLLVM\Type $type): void {
        $this->typeMap[$name] = $type;
    }

    /**
     * Prefer an existing module named struct (edit-scaffold bitcode) over CreateNamed (#36387).
     *
     * CreateNamed after parseBitcode uniqueifies (__string__.11) and breaks GEPs.
     */
    public function namedStructType(string $name): PHPLLVM\Type
    {
        if (CompileCache::isEditScaffoldActive() || null !== CompileCache::pendingEditScaffoldKey()) {
            try {
                $existing = $this->module->getTypeByName($name);
                if ($existing instanceof PHPLLVM\Type\Struct) {
                    return $existing;
                }
                if ($existing instanceof PHPLLVM\Type) {
                    try {
                        $existing->getKind();

                        return $existing;
                    } catch (\Throwable $e) {
                        // null wrapper — fall through
                    }
                }
            } catch (\Throwable $e) {
                // fall through to CreateNamed
            }
        }

        return $this->context->namedStructType($name);
    }

    /**
     * setBody only when the named struct is still opaque (bitcode already defined it) (#36387).
     */
    public function setNamedStructBody(PHPLLVM\Type $struct, bool $packed, PHPLLVM\Type ...$elements): void
    {
        if ($struct instanceof PHPLLVM\Type\Struct) {
            try {
                if (!$struct->isOpaque()) {
                    return;
                }
            } catch (\Throwable $e) {
                // try setBody anyway
            }
        }
        if (!method_exists($struct, 'setBody')) {
            return;
        }
        $struct->setBody($packed, ...$elements);
    }

    public function constantFromInteger(int $value, ?string $type = null): PHPLLVM\Value {
        return $this->getTypeFromString($type === null ? 'long long' : $type)->constInt($value, $value < 0);
    }

    public function constantFromFloat(float $value, ?string $type = null): PHPLLVM\Value {
        $llvmType = $this->getTypeFromString($type === null ? 'double' : $type);
        // PHP FFI LLVMConstReal(double) drops IEEE specials: -INF becomes 2^63 (#32317).
        // Positive inf/nan via LLVMConstRealOfString; -INF via LLVMConstFNeg(+inf)
        // (llvm-c Core.h — zend_operators.c zendi_negate_function analog).
        if (\is_nan($value) || \is_infinite($value)) {
            $text = \is_nan($value) ? 'nan' : 'inf';
            $raw = $this->llvm->lib->LLVMConstRealOfString($llvmType->type, $text);
            if (null === $raw) {
                throw new \LogicException('LLVMConstRealOfString failed for '.$text.' (#32317)');
            }
            if (\is_infinite($value) && $value < 0.0) {
                $neg = $this->llvm->lib->LLVMConstFNeg($raw);
                if (null === $neg) {
                    throw new \LogicException('LLVMConstFNeg failed for -inf (#32317)');
                }
                $raw = $neg;
            }

            return $this->llvm->factory->value($this->context, $raw);
        }

        return $llvmType->constReal($value);
    }

    public function constantFromString(string $string): PHPLLVM\Value {
        if (!isset($this->stringConstant[$string])) {
            $const = $this->context->constString($string, true);
            // Avoid LLVM symbol names that match POSIX/CGI env var names (e.g. SERVER_PROTOCOL)
            // which break getenv() linkage in the AOT binary (issue #306).
            $globalName = 'php_cstr_' . hash('sha256', $string);
            $global = $this->module->addGlobal($const->typeOf(), $globalName);
            $global->setInitializer($const);
            $this->stringConstant[$string] = $global;
        }
        return $this->stringConstant[$string];
    }

    /** NUL-terminated C string pointer for a module string global. */
    public function pointerFromStringConstant(string $string): PHPLLVM\Value
    {
        return $this->bytePtr($this->constantFromString($string));
    }

    /** C-style int success flag (non-zero => true) for branch/select lowering. */
    public function i32Success(PHPLLVM\Value $value): PHPLLVM\Value
    {
        return $this->builder->icmp(
            PHPLLVM\Builder::INT_NE,
            $value,
            $this->getTypeFromString('int32')->constInt(0, false)
        );
    }

    /** Bitcast any pointer to i8* for libc helpers declared with int8* parameters. */
    public function bytePtr(PHPLLVM\Value $value): PHPLLVM\Value
    {
        return $this->builder->pointerCast($value, $this->getTypeFromString('int8*'));
    }

    public function constantFromBool(bool $value): PHPLLVM\Value {
        $id = $value ? 1 : 0;
        if (!isset($this->boolValues[$id])) {
            $this->boolValues[$id] = $this->getTypeFromString('bool')->constInt($id, false);
        }
        return $this->boolValues[$id];
    }

    public function constantStringFromString(string $string): PHPLLVM\Value {
        if (!isset($this->stringConstantMap[$string])) {
            // Per-unit / main suffix — same collision class as __init__/__shutdown__ (#15889 /
            // #16075). Bare string_const_N merges across helper-runtime .o files; later unit
            // __init__ overwrites the main script's literals (SessionsWeb sid became
            // "/index.php"; session wire encode emptied — #26411).
            $globalName = $this->moduleLocalConstGlobalName('string_const_', \count($this->stringConstantMap));
            $this->stringConstantMap[$string] = StaticImmortalStringLlvm::definePtrGlobal(
                $this,
                $string,
                $globalName
            );
        }
        return $this->stringConstantMap[$string];
    }

    /**
     * Module global for a compile-time constant array (eager __init__ — #4904, #4941).
     */
    public function constantArrayFromVmHashTable(string $cacheKey, \PHPCompiler\VM\HashTable $table): PHPLLVM\Value
    {
        if (!isset($this->arrayConstantMap[$cacheKey])) {
            $ptrTy = $this->getTypeFromString('__value__*');
            $global = $this->module->addGlobal(
                $ptrTy,
                $this->moduleLocalConstGlobalName('array_const_', \count($this->arrayConstantMap))
            );
            $global->setInitializer($ptrTy->constNull());
            $this->arrayConstantMap[$cacheKey] = $global;
            $this->emitConstantArrayInitInInitBlock($global, $table);
        }

        return $this->arrayConstantMap[$cacheKey];
    }

    /**
     * Immortal module global for file-scope {@code const C = new …} (#35196).
     *
     * Peer of class-const object globals ({@see Builtin\Type\Object_::defineClassConst}) and
     * file-scope enum rematerialization (#34783). Copies declared property values from the
     * VM ObjectEntry so constructor args survive AOT CONST_FETCH.
     *
     * php-src: Zend/zend_constants.c — file consts hold persistent object zvals.
     */
    public function constantObjectFromVm(string $cacheKey, VMVariable $phpVar): PHPLLVM\Value
    {
        if (isset($this->objectConstantMap[$cacheKey])) {
            return $this->objectConstantMap[$cacheKey];
        }
        if (isset($this->constants[$cacheKey][1])) {
            return $this->constants[$cacheKey][1];
        }
        $phpVar = $phpVar->resolveIndirect();
        if (VMVariable::TYPE_OBJECT !== $phpVar->type) {
            throw new \LogicException('constantObjectFromVm requires TYPE_OBJECT');
        }
        $object = $phpVar->toObject();
        $className = $object->class->name;
        $classLc = strtolower(ltrim($className, '\\'));
        $classId = $this->type->object->lookup($classLc);
        $objPtrType = $this->getTypeFromString('__object__*');
        $global = $this->module->addGlobal(
            $objPtrType,
            $this->moduleLocalConstGlobalName('object_const_', \count($this->objectConstantMap))
        );
        $global->setInitializer($objPtrType->constNull());
        $this->objectConstantMap[$cacheKey] = $global;
        /** @var array<string, VMVariable> $propSnapshot */
        $propSnapshot = [];
        foreach ($object->propertiesWithNames() as $propName => $propVar) {
            if (!\is_string($propName) || '' === $propName) {
                continue;
            }
            $propSnapshot[$propName] = \PHPCompiler\VM\ClassConstMaterializer::detachConstantValue($propVar);
        }
        $this->emitInInit(function (Context $ctx) use ($classId, $global, $propSnapshot): void {
            $alloc = $ctx->type->object->allocateClassConstantObject($classId);
            foreach ($propSnapshot as $propName => $propVm) {
                $nameId = $ctx->type->object->propNameIdFor($propName);
                $propset = null !== $nameId
                    ? $ctx->type->object->resolvePropertySetForNameId($classId, $nameId)
                    : null;
                if (null === $propset) {
                    $jitType = Variable::fromVMVariable($propVm->type);
                    $ctx->type->object->defineProperty($classId, $propName, $jitType);
                    $nameId = $ctx->type->object->propNameIdAfterDefine($propName);
                    if (null === $nameId) {
                        continue;
                    }
                    $propset = $ctx->type->object->resolvePropertySetForNameId($classId, $nameId);
                }
                if (null === $propset) {
                    continue;
                }
                try {
                    $jitVal = VmConstantJit::toVariable($ctx, $propVm);
                } catch (\Throwable) {
                    continue;
                }
                $slot = $ctx->type->object->propertySlotPtr($alloc, $propset[3]);
                $ctx->type->object->propertyStore($slot, $jitVal, $propset[2]);
            }
            $ctx->builder->store($alloc, $global);
        });
        $this->constants[$cacheKey] = [Variable::TYPE_OBJECT, $global];

        return $global;
    }

    /**
     * LLVM global name for module-local compile-time constants.
     *
     * Helper units set {@see PHP_COMPILER_INIT_SYMBOL_SUFFIX}; the main script uses
     * `_main` so bare string_const_N from stale prelinked helpers cannot clobber it.
     */
    private function moduleLocalConstGlobalName(string $prefix, int $index): string
    {
        $suffix = (string) Config::getenv('PHP_COMPILER_INIT_SYMBOL_SUFFIX');
        if ('' === $suffix) {
            $suffix = '_main';
        }

        return $prefix.$index.$suffix;
    }

    /** @deprecated Inline lazy-init removed; arrays initialize in __init__ (#4941). */
    public function ensureConstantArrayLazyInit(string $cacheKey): void
    {
    }

    private function emitConstantArrayInitInInitBlock(PHPLLVM\Value $global, \PHPCompiler\VM\HashTable $table): void
    {
        $oldBuilder = $this->builder;
        $this->builder = $this->context->builderCreate();
        $this->positionBuilderAtInitEmission();
        $htVar = HashTableHelper::variableFromVmHashTable($this, $table);
        $ht = HashTableHelper::loadHashtablePointer($this, $htVar);
        $this->refcount->addref($ht);
        $valueType = $this->getTypeFromString('__value__');
        $heapVal = $this->memory->malloc($valueType);
        $heapPtr = $this->builder->pointerCast(
            $heapVal,
            $this->getTypeFromString('__value__*')
        );
        $this->builder->call(
            $this->lookupFunction('__value__writeHashtable'),
            $heapPtr,
            $ht
        );
        $this->builder->store($heapPtr, $global);
        $this->builder = $oldBuilder;
    }

    private function materializeVmHashTableForConstInit(\PHPCompiler\VM\HashTable $table): PHPLLVM\Value
    {
        $ht = HashTableHelper::alloc($this);
        $setLong = $this->lookupFunction('__hashtable__setLongAt');
        $i64 = $this->getTypeFromString('int64');
        foreach ($table->iterateKeyed(true) as [$keyVar, $valueVar]) {
            $resolved = $valueVar->resolveIndirect();
            if (VMVariable::TYPE_INTEGER !== $keyVar->type) {
                return $this->helper->loadValue(
                    HashTableHelper::variableFromVmHashTable($this, $table)
                );
            }
            $idx = $this->constantFromInteger($keyVar->toInt(), 'size_t');
            if (VMVariable::TYPE_INTEGER === $resolved->type) {
                $this->builder->call(
                    $setLong,
                    $ht,
                    $idx,
                    $i64->constInt($resolved->toInt(), false)
                );
            } elseif (VMVariable::TYPE_ARRAY === $resolved->type) {
                $nested = $this->materializeVmHashTableForConstInit($resolved->toArray());
                $this->refcount->addref($nested);
                $nestedVar = new Variable(
                    $this,
                    Variable::TYPE_HASHTABLE,
                    Variable::KIND_VALUE,
                    $nested
                );
                HashTableHelper::setAtIndex($this, $ht, $idx, $nestedVar);
            } else {
                return $this->helper->loadValue(
                    HashTableHelper::variableFromVmHashTable($this, $table)
                );
            }
        }

        return $ht;
    }

}
