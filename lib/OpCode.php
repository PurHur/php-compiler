<?php

/*
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler;

class OpCode {
    /** arg2 = echo-statement startLine when known (#5134). */
    const TYPE_ECHO = 1;
    const TYPE_ASSIGN = 2;
    const TYPE_CONCAT = 3;
    const TYPE_JUMP = 4;
    const TYPE_CONST_FETCH = 5;
    const TYPE_JUMPIF = 6;
    const TYPE_PLUS = 7;
    const TYPE_SMALLER = 8;
    const TYPE_RETURN_VOID = 9;
    const TYPE_FUNCDEF = 10;
    const TYPE_FUNCCALL_INIT = 11;
    const TYPE_ARG_SEND = 12;
    const TYPE_ARG_RECV = 13;
    /** arg2 = call-site line when known (#4482). */
    const TYPE_FUNCCALL_EXEC_RETURN = 14;
    /** arg1 = call-site line when known (#4482). */
    const TYPE_FUNCCALL_EXEC_NORETURN = 15;
    const TYPE_IDENTICAL = 16;
    const TYPE_RETURN = 17;
    const TYPE_MINUS = 18;
    const TYPE_DECLARE_CLASS = 19;
    /** arg3 = new-expression startLine when known (#195). */
    const TYPE_NEW = 20;
    const TYPE_MUL = 21;
    const TYPE_DIV = 22;
    const TYPE_GREATER = 23;
    const TYPE_DECLARE_PROPERTY = 24;
    const TYPE_PROPERTY_FETCH = 25;
    /** Property fetch for assign/ref lvalues and AssignRef RHS (#13559, Zend FETCH_OBJ_W). */
    const TYPE_PROPERTY_FETCH_WRITE = 128;
    const TYPE_UNARY_MINUS = 26;
    const TYPE_UNARY_PLUS = 27;
    const TYPE_BITWISE_NOT = 28;
    const TYPE_BOOLEAN_NOT = 29;
    /** arg3 = print-expression startLine when known (#5134). */
    const TYPE_PRINT = 30;
    const TYPE_CLONE = 31;
    const TYPE_EMPTY = 32;
    const TYPE_EVAL = 33;
    /** arg3 = exit/die expression startLine when known (#6358). */
    const TYPE_EXIT = 34;
    /** TYPE_EXIT: optional message operand slot for exit($status, $message) (#6718). */
    public ?int $exitMessageSlot = null;
    const TYPE_SMALLER_OR_EQUAL = 35;
    const TYPE_GREATER_OR_EQUAL = 36;
    const TYPE_CAST_ARRAY = 37;
    const TYPE_CAST_BOOL = 38;
    const TYPE_CAST_FLOAT = 39;
    const TYPE_CAST_INT = 40;
    const TYPE_CAST_OBJECT = 41;
    const TYPE_CAST_STRING = 42;
    const TYPE_CAST_UNSET = 43;
    const TYPE_EQUAL = 44;
    const TYPE_ARRAY_DIM_FETCH=45;
    /** Array dim fetch for assignment lvalues (creates missing keys; issue #103). */
    const TYPE_ARRAY_DIM_FETCH_WRITE=83;
    const TYPE_MODULO = 46;
    const TYPE_SWITCH = 47;
    const TYPE_CASE = 48;
    const TYPE_BITWISE_AND = 49;
    const TYPE_BITWISE_OR = 50;
    const TYPE_BITWISE_XOR = 51;
    const TYPE_TYPE_ASSERT = 52;
    const TYPE_INIT_ARRAY = 53;
    const TYPE_ADD_ARRAY_ELEMENT = 54;
    const TYPE_STATICCALL_INIT = 55;
    const TYPE_INCLUDE = 56;
    const TYPE_ISSET = 57;
    const TYPE_POW = 58;
    const TYPE_NOT_EQUAL = 59;
    const TYPE_NOT_IDENTICAL = 60;
    const TYPE_SPACESHIP = 61;
    const TYPE_COALESCE = 62;
    const TYPE_NULLSAFE = 63;
    const TYPE_ITER_RESET = 64;
    const TYPE_ITER_VALID = 65;
    const TYPE_ITER_KEY = 66;
    const TYPE_ITER_VALUE = 67;
    const TYPE_SHIFT_LEFT = 68;
    const TYPE_SHIFT_RIGHT = 69;
    /** arg2 = method declaration startLine when known (#6914). */
    const TYPE_DECLARE_METHOD = 84;
    const TYPE_METHODCALL_INIT = 85;
    const TYPE_DECLARE_CLASS_CONST = 86;
    const TYPE_CLASS_CONST_FETCH = 87;
    /** arg2 = throw-statement startLine when known (#195). */
    const TYPE_THROW = 88;
    const TYPE_INSTANCEOF = 89;
    const TYPE_STATIC_PROPERTY_FETCH = 90;
    const TYPE_UNSET = 91;
    /** AOT lint: try/catch CFG lowering; VM/JIT exception model deferred (issue #57). */
    const TYPE_TRY = 92;
    /**
     * Catch handler entry (issue #57, multi-type #1362).
     * block1 = catch body; block2 = merge after try/catch.
     * catchTypes = pipe-separated lowercase caught class names (`a|b`).
     * Encoded `&` (`a&b`) is not produced under php-src-strict (#28439).
     * arg3 = catch variable scope slot in catch body, or null.
     */
    const TYPE_CATCH = 93;
    const TYPE_FINALLY = 94;
    const TYPE_DECLARE_GLOBAL_CONST = 95;
    /** {@see TYPE_DECLARE_GLOBAL_CONST} source line for duplicate-const E_WARNING (#6938). */
    public int $globalConstStartLine = 0;
    /** Runtime __DIR__ / __FILE__ / __LINE__ (issues #707, #715). arg2 = line when LINE; arg3 = SCRIPT_MAGIC_* kind. */
    const TYPE_SCRIPT_MAGIC = 96;

    public const SCRIPT_MAGIC_DIR = 1;

    public const SCRIPT_MAGIC_FILE = 2;

    public const SCRIPT_MAGIC_LINE = 3;

    /** __COMPILER_HALT_OFFSET__ — byte offset of halt trailing data (#5455). */
    public const SCRIPT_MAGIC_HALT_OFFSET = 4;

    /** include/require kind encoded for TYPE_INCLUDE (issue #4426). */
    public const INCLUDE_KIND_INCLUDE = 1;
    public const INCLUDE_KIND_INCLUDE_ONCE = 2;
    public const INCLUDE_KIND_REQUIRE = 3;
    public const INCLUDE_KIND_REQUIRE_ONCE = 4;

    const TYPE_ASSIGN_REF = 97;
    /** {@see TYPE_ASSIGN_REF} arg3: foreach `as &$obj->hookedProp` iteration assign (#6435). */
    const ASSIGN_REF_FOREACH_PROPERTY_HOOK = 2;
    const TYPE_DECLARE_GLOBAL = 98;
    const TYPE_DECLARE_STATIC_PROPERTY = 99;
    /** Dynamic variable fetch: `$$name` where arg2 holds the name variable (#1226). */
    const TYPE_VAR_FETCH = 100;
    /** Append all elements from arg2 array into arg1 array (array literal ...$src, issue #141). */
    const TYPE_ARRAY_SPREAD = 101;
    /** Register a user interface name (#1357). */
    const TYPE_DECLARE_INTERFACE = 102;
    /** User enum declaration with case constants (#1356). */
    const TYPE_DECLARE_ENUM = 103;
    /** unset(Class::$prop) — arg2 class, arg3 property name (#2256). */
    const TYPE_STATIC_PROPERTY_UNSET = 104;
    /**
     * Function-local static: arg1 local slot, arg2 storage key constant slot, arg3 compile-time default slot (#2286).
     * When arg3 is null, bind only — runtime init via TYPE_FUNCTION_STATIC_INIT_STORE (#4352).
     */
    const TYPE_DECLARE_FUNCTION_STATIC = 105;
    /** User trait declaration with method bodies (#2312). */
    const TYPE_DECLARE_TRAIT = 106;
    /** Import trait methods into a class body (`use SomeTrait;`, issue #2314). */
    const TYPE_USE_TRAIT = 107;
    /**
     * Trait use adaptations (`insteadof` / `as`) for the preceding TYPE_USE_TRAIT group (#3238).
     *
     * @see traitAdaptations
     */
    const TYPE_TRAIT_USE_ADAPTATION = 119;
    /** Suspend generator and expose value/key to foreach (issue #167). arg2=value slot, arg3=key slot. */
    const TYPE_YIELD = 108;
    /**
     * Delegate yields from an array / generator (issue #167).
     *
     * VM-only today: arg2 = container slot (array or Generator).
     */
    const TYPE_YIELD_FROM = 110;
    /**
     * Closure literal (`function (...) { ... }` / `fn (...) => ...`).
     *
     * arg1 = destination scope slot (result).
     * block1 = compiled anonymous function body (VM-only today; JIT still lowers to null).
     */
    const TYPE_CLOSURE = 109;
    /** Bare `throw;` in catch — rethrow active caught exception (#3508). */
    const TYPE_RETHROW = 111;
    /** Begin `@` error-control: mask E_WARNING/E_NOTICE for wrapped expression (issue #3546). */
    const TYPE_BEGIN_SILENCE = 112;
    /** End `@` error-control: restore prior error_reporting (issue #3546). */
    const TYPE_END_SILENCE = 113;
    /** Post-increment: arg1=result, arg2=read, arg3=write (#3552). */
    const TYPE_POST_INC = 114;
    /** Pre-increment: arg1=result, arg2=read, arg3=write (#3552). */
    const TYPE_PRE_INC = 115;
    /** Post-decrement: arg1=result, arg2=read, arg3=write (#3552). */
    const TYPE_POST_DEC = 116;
    /** Pre-decrement: arg1=result, arg2=read, arg3=write (#3552). */
    const TYPE_PRE_DEC = 117;
    /** Logical xor (`$a xor $b`): both operands evaluated, truthiness exclusive-or (#2313). */
    const TYPE_LOGICAL_XOR = 118;
    /** `list()` / `[]` unpack: arg2 = array slot; skip assigns when not array (block1 merge, #4325). */
    const TYPE_LIST_UNPACK_CHECK = 120;
    /** Skip runtime static init when storage key (arg2) is already initialized; jump to block1 (#4352). */
    const TYPE_JUMPIF_FUNCTION_STATIC_INITIALIZED = 121;
    /** Store runtime static default: arg2 = key constant slot, arg3 = value slot (#4352). */
    const TYPE_FUNCTION_STATIC_INIT_STORE = 122;
    /** PHP 8.3+ `$needle in $haystack` strict contains (#4682). arg2=needle, arg3=haystack. */
    const TYPE_IN = 123;
    /** `[$a, ...$rest] = $list` tail: arg1=dest, arg2=source array, arg3=from-index constant slot (#4835). */
    const TYPE_LIST_SPREAD_ASSIGN = 124;
    /**
     * Wrap compile-time callable (string or `[obj, method]` array) in a Closure object (#4810).
     *
     * arg1 = destination slot; arg2 = callable value slot (string or array).
     */
    const TYPE_FROM_CALLABLE = 125;
    /** empty($obj->prop): uninitialized typed slots empty without read; else value truthiness (#6787, #23983). */
    const TYPE_EMPTY_OBJECT_PROPERTY = 126;
    /** `(void)` cast — evaluate operand, result is null (#7346). */
    const TYPE_CAST_VOID = 127;
    /** empty($container[$dim]): ArrayAccess checks offsetGet truthiness, not isset alone (#14798). */
    const TYPE_EMPTY_DIMENSION = 129;

    /** declare(ticks=N) — enter scope and push previous interval (#3343). */
    const TYPE_TICK_SCOPE_ENTER = 130;

    /** declare(ticks=N) — update interval within an open tick scope (#3343). */
    const TYPE_TICK_SCOPE_SET = 131;

    /** End declare(ticks=N) block scope — restore previous interval (#3343). */
    const TYPE_TICK_SCOPE_LEAVE = 132;

    /**
     * Statement-boundary tick check (Zend tickable statement cadence, #22840).
     * Runtime decrements EG-style counter and may invoke register_tick_function handlers.
     */
    const TYPE_TICKS = 133;

    /**
     * empty(Class::$prop): uninitialized typed statics empty without read; else value truthiness (#23983, #15112).
     * arg1=dest bool, arg2=class operand, arg3=property name.
     */
    const TYPE_EMPTY_STATIC_PROPERTY = 134;

    /** `['k' => $v, ...$tail] = $arr` string keys already assigned; empty = numeric spread only (#4889). */
    public array $listSpreadExcludedKeys = [];

    /** Guarded list destruct: scope slots to null when RHS is not unpackable (#4325, #10486). */
    public array $listUnpackNullInitSlots = [];

    /**
     * Guarded list destruct includes by-ref slots (`[&$r] = …` / `$r =& $rhs[$i]`).
     * Non-array RHS must not skip ASSIGN_REF — Zend raises string-offset / scalar-as-array (#21910).
     */
    public bool $listUnpackHasByRef = false;

    public int $type;
    public ?int $arg1;
    public ?int $arg2;
    public ?int $arg3;
    /** @var ?Block */
    public $block1 = null;
    /** @var ?Block */
    public $block2 = null;
    /** @var ?Block */
    public $block3 = null;

    /** @var list<string> lowercase interface names for implements/extends (#25624). */
    public array $classImplements = [];
    /**
     * Display names parallel to {@see $classImplements} (source casing for Error messages, #25624).
     *
     * @var list<string>
     */
    public array $classImplementsDisplay = [];
    /** Sealed type: permitted child class names (lowercase FQCN); empty = none (#3322). */
    public bool $isSealed = false;
    /** @var list<string> */
    public array $sealedPermits = [];
    /** Declared PHP 8 attribute class names on class/method (#1936). */
    public array $attributeNames = [];
    /** @var list<\PHPCompiler\Compiler\AttributeEntry> attribute metadata incl. ctor args (#3206, #3800). */
    public array $attributeEntries = [];
    /** @var list<\PHPCompiler\Compiler\ParameterMetadata> method parameter metadata (#3340). */
    public array $parameterMetadata = [];
    /**
     * TYPE_DECLARE_METHOD: declared return type AST (including abstract/interface methods with no body).
     * Needed for cross-file / eval inheritance variance (#25384); same-script uses InheritanceVariance on CFG.
     *
     * @var ?\PHPCfg\Op\Type
     */
    public $returnDeclaredType = null;
    /** True when TYPE_DECLARE_CLASS targets an abstract class (#3385). */
    public bool $classIsAbstract = false;
    /** #[\Deprecated] metadata on function/method/class const declarations (#3569). */
    public ?\PHPCompiler\Compiler\DeprecatedMetadata $deprecatedMetadata = null;
    /** Pipe-separated lowercase catch class names for TYPE_CATCH (#1362; `&` unused under php-src-strict #28439). */
    public ?string $catchTypes = null;
    /** Pipe-separated lowercase class/interface names for union `instanceof` RHS (#3461). */
    public ?string $instanceofUnionTypes = null;

    /** TYPE_DECLARE_PROPERTY: property is readonly (#3149, #3432). */
    public bool $propertyReadonly = false;
    /** TYPE_DECLARE_PROPERTY: PHP 8.4 final modifier — ZEND_ACC_FINAL (#22241, #20511). */
    public bool $propertyFinal = false;
    /** TYPE_DECLARE_PROPERTY: PHP 8.4 lazy modifier — deferred default init (#16813). */
    public bool $propertyLazy = false;
    /** TYPE_DECLARE_PROPERTY: constructor promotion (#4758, #5091). */
    public bool $propertyFromConstructorPromotion = false;
    /** TYPE_DECLARE_PROPERTY: PHPCfg visibility flags (#145). */
    public int $propertyVisibility = 0;
    /** TYPE_DECLARE_PROPERTY / TYPE_DECLARE_STATIC_PROPERTY: cfg declared type for runtime validation (#17996). */
    public ?\PHPCfg\Op\Type $cfgDeclaredType = null;
    /** TYPE_DECLARE_FUNCTION_STATIC: declared type prototype constant slot (#9998). */
    public ?int $functionStaticTypeSlot = null;
    /** TYPE_DECLARE_FUNCTION_STATIC / TYPE_FUNCTION_STATIC_INIT_STORE: variable name for TypeError (#9998). */
    public ?string $functionStaticVarName = null;
    /** TYPE_ECHO: {main} script-global CV when literal slot would stale-fold (#23842). */
    public ?string $echoScriptGlobalName = null;

    /**
     * Closure `use ($var)` metadata for TYPE_CLOSURE (issue #72).
     *
     * @var list<array{name: string, slot: int, byRef: bool}>
     */
    public array $closureCaptures = [];

    /** Lowered from ++/-- (issue #3469); enables Zend increment_string on strings. */
    public bool $isIncDec = false;
    /** isset()/empty() on PropertyFetch, not ArrayDimFetch (issue #5117, zend_hash.c). */
    public bool $issetOnProperty = false;
    /**
     * TYPE_ARRAY_DIM_FETCH in isset()/empty() nested-dim chains — Zend FETCH_DIM_IS / BP_VAR_IS
     * (no undefined-key / scalar-offset warnings; missing → null) (#21991, zend_execute.c).
     */
    public bool $arrayDimFetchIs = false;
    /** unset() on PropertyFetch, not ArrayDimFetch (issue #19681, SimpleXML sxe_prop_dim_delete). */
    public bool $unsetOnProperty = false;
    /** ?? / ??= on static hooked properties: probe backing storage, not get hook (#9683). */
    public bool $issetOnStaticProperty = false;
    /** ?? / ??= on hooked properties: null-check backing storage, not get-hook value (#6472, #8902). */
    public bool $issetForCoalesceAssign = false;
    /** ?? / ??= left branch: read backing storage, not get-hook value (#6472, #8902). */
    public bool $propertyHookCoalesceRead = false;
    /** TYPE_PROPERTY_FETCH in a ?-> fetch arm must read typed slots (#5361, zend_object_handlers.c). */
    public bool $nullsafeFetchPropertyRead = false;
    /** ?-> fetch arm: uninitialized nullable typed slot → null not Error (#5220, #13747). */
    public bool $nullsafeUninitNullableToNull = false;
    /**
     * TYPE_NULLSAFE for ?-> method call (vs property): both short-circuit only on null;
     * method fetch arm Errors on scalar, property fetch arm warns (#26364, #26365).
     */
    public bool $nullsafeMethodCall = false;
    /**
     * Trait use adaptation entries for TYPE_TRAIT_USE_ADAPTATION (#3238).
     *
     * Each element: alias `{kind: alias, trait: ?string, method: string, newName: ?string}`
     * or precedence `{kind: precedence, trait: string, method: string, insteadof: list<string>}`.
     *
     * @var list<array<string, mixed>>
     */
    public array $traitAdaptations = [];
    /** Asymmetric set visibility on TYPE_DECLARE_PROPERTY (#3165); 0 = symmetric with read. */
    public int $propertySetVisibility = 0;
    /** Asymmetric get visibility on TYPE_DECLARE_PROPERTY (#5059); 0 = symmetric with write. */
    public int $propertyGetVisibility = 0;
    /** Explicit read modifier before asymmetric set in source (#15995). */
    public bool $propertyAsymmetricExplicitRead = false;
    /** TYPE_CLASS_CONST_FETCH: `::class` on a runtime expression operand (must be object, #4241). */
    public bool $classConstFetchOnObject = false;
    /** TYPE_DECLARE_CLASS_CONST: PHPCfg visibility flags (#4651). */
    public int $classConstVisibilityFlags = 0;
    /** TYPE_DECLARE_CLASS_CONST: `case` in enum body vs user `const` (#5054, zend_enum.c). */
    public bool $isEnumCaseDeclare = false;
    /** TYPE_STATICCALL_INIT: source was `parent::` (php-cfg may lower class operand to fqcn). */
    public bool $staticCallParentScope = false;
    /**
     * TYPE_FROM_CALLABLE: scoped `parent::` / `self::` FCC (#17655, #26630, #27835, zend_compile.c).
     *
     * null = virtual/`static::` or `$obj->m(...)` / named `Class::m(...)`;
     * `'parent'` / `'self'` pin the resolve class and (for static methods) preserve creation-time
     * late-static called_scope when the Class::method string was baked to a FQCN.
     */
    public ?string $fromCallableScope = null;
    /**
     * TYPE_FROM_CALLABLE: lowered from Closure::fromCallable() (not FCC `$name(...)`) (#27138).
     *
     * Enables Zend bind-$this for `[Class, instanceMethod]` and TypeError message prefix.
     */
    public bool $fromCallableApi = false;
    /**
     * TYPE_FUNCCALL_INIT: callee was a variable/expression, not a literal name (#23591).
     *
     * Zend ZEND_ACC_FORBIDDEN_WHEN_DYNAMIC — even when the name folds to a compile-time string.
     */
    public bool $funcCallDynamic = false;
    /**
     * TYPE_METHODCALL_INIT: FuncCall `$obj(...)` rewritten to `__invoke` (zend_object_handlers.c).
     *
     * Object-call dispatch ignores declared visibility (warns at declare); explicit
     * `$obj->__invoke()` still enforces it (#26438).
     */
    public bool $objectCallInvoke = false;

    /** TYPE_INCLUDE: include/require + once/non-once semantics (issue #4426). */
    public int $includeKind = self::INCLUDE_KIND_INCLUDE_ONCE;
    /** Docblock + source file/line for reflection (#7358). */
    public ?\PHPCompiler\Compiler\SourceLocation $sourceLocation = null;

    public function __construct(int $type, ?int $arg1 = null, ?int $arg2 = null, ?int $arg3 = null) {
        $this->type = $type;
        $this->arg1 = $arg1;
        $this->arg2 = $arg2;
        $this->arg3 = $arg3;
    }

    /**
     * True when this opcode assigns through {@see $destSlot} as lvalue (#5370, #6426).
     *
     * Ternary / `&&` true-arms often PropertyFetch into the phi slot then emit
     * `ASSIGN dest=phi, rhs=phi` (arg2 === arg3 === fetch dest). That is a value
     * reuse, not `$obj->prop = …` — treating it as a write falsely trips readonly
     * and skips `__get` (#23986).
     */
    public static function destSlotUsedAsAssignLvalue(self $op, int $destSlot): bool
    {
        return (self::TYPE_ASSIGN === $op->type && $op->arg2 === $destSlot && $op->arg3 !== $destSlot)
            || (self::TYPE_ASSIGN_REF === $op->type && ($op->arg1 === $destSlot || $op->arg2 === $destSlot))
            || (self::TYPE_POST_INC === $op->type && $op->arg3 === $destSlot)
            || (self::TYPE_PRE_INC === $op->type && $op->arg3 === $destSlot)
            || (self::TYPE_POST_DEC === $op->type && $op->arg3 === $destSlot)
            || (self::TYPE_PRE_DEC === $op->type && $op->arg3 === $destSlot)
            || self::destSlotUsedAsInPlaceCompoundAssign($op, $destSlot);
    }

    /**
     * True when {@see $destSlot} is the RHS of ASSIGN_REF (`$r = &$obj->prop`, #22475).
     * Defer get-only / `&get` checks to TYPE_ASSIGN_REF — do not treat as write-only lvalue.
     */
    public static function destSlotUsedAsAssignRefSource(self $op, int $destSlot): bool
    {
        return self::TYPE_ASSIGN_REF === $op->type && $op->arg2 === $destSlot;
    }

    /**
     * True when this opcode uses {@see $destSlot} as the container for dim mutation
     * ($prop[]= / $prop[k]= write, or unset($prop[k]) — #6775, #24250).
     *
     * Property R-mode fetches must alias live storage for these consumers; a copy makes
     * the mutation a no-op on the instance property (Zend zend_execute.c ZEND_UNSET_DIM).
     */
    public static function destSlotUsedAsDimWriteContainer(self $op, int $destSlot): bool
    {
        if (self::TYPE_ARRAY_DIM_FETCH_WRITE === $op->type && $op->arg2 === $destSlot) {
            return true;
        }
        // unset($container[$dim]) — arg2 is container, arg3 is dim (#24250).
        return self::TYPE_UNSET === $op->type
            && $op->arg2 === $destSlot
            && null !== $op->arg3
            && !$op->unsetOnProperty;
    }

    /**
     * True when {@see $destSlot} is the foreach container for a by-ref value fetch
     * (`foreach ($obj->prop as &$v)` — FE_RESET_RW / #29215).
     */
    public static function destSlotUsedAsByRefForeachValueContainer(self $op, int $destSlot): bool
    {
        return self::TYPE_ITER_VALUE === $op->type
            && (int) $op->arg2 === $destSlot
            && (bool) $op->arg3;
    }

    /** True when this opcode reads {@see $destSlot} as lhs in fetch-op-assign compound lowering (#6438). */
    public static function destSlotUsedAsCompoundAssignRead(self $op, int $destSlot): bool
    {
        if ($op->arg2 !== $destSlot || $op->arg1 === $destSlot) {
            return false;
        }

        return match ($op->type) {
            self::TYPE_CONCAT,
            self::TYPE_PLUS,
            self::TYPE_MINUS,
            self::TYPE_MUL,
            self::TYPE_DIV,
            self::TYPE_MODULO,
            self::TYPE_POW,
            self::TYPE_BITWISE_AND,
            self::TYPE_BITWISE_OR,
            self::TYPE_BITWISE_XOR,
            self::TYPE_SHIFT_LEFT,
            self::TYPE_SHIFT_RIGHT => true,
            default => false,
        };
    }

    /** True when this opcode mutates {@see $destSlot} in-place (arg1 === arg2) (#6438). */
    public static function destSlotUsedAsInPlaceCompoundAssign(self $op, int $destSlot): bool
    {
        if ($op->arg1 !== $destSlot || $op->arg2 !== $destSlot) {
            return false;
        }

        return match ($op->type) {
            self::TYPE_CONCAT,
            self::TYPE_PLUS,
            self::TYPE_MINUS,
            self::TYPE_MUL,
            self::TYPE_DIV,
            self::TYPE_MODULO,
            self::TYPE_POW,
            self::TYPE_BITWISE_AND,
            self::TYPE_BITWISE_OR,
            self::TYPE_BITWISE_XOR,
            self::TYPE_SHIFT_LEFT,
            self::TYPE_SHIFT_RIGHT => true,
            default => false,
        };
    }

}
