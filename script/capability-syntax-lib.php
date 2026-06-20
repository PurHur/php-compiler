<?php

declare(strict_types=1);

/**
 * Shared language-construct capability helpers for capability-matrix.php and capability-syntax.php.
 */

const CAPABILITY_ISSUE_URL_BASE = 'https://github.com/PurHur/php-compiler/issues/';

/**
 * @return list<array{
 *   id: string,
 *   construct: string,
 *   opcodes: list<string>,
 *   issue: int,
 *   notes: list<string>,
 *   probe: ?string,
 *   aot?: bool
 * }>
 */
function syntaxRowDefinitions(): array
{
    return [
        [
            'id' => 'class_new',
            'construct' => '`class` / `new`',
            'opcodes' => ['TYPE_DECLARE_CLASS', 'TYPE_NEW'],
            'issue' => 58,
            'notes' => [],
            'probe' => 'class C { public int $x = 1; } echo (new C())->x;',
        ],
        [
            'id' => 'anonymous_class',
            'construct' => 'Anonymous class `new class { }`',
            'opcodes' => ['TYPE_DECLARE_CLASS', 'TYPE_NEW', 'TYPE_METHODCALL_INIT'],
            'issue' => 1233,
            'aot' => true,
            'notes' => [
                'php-cfg inline Stmt\\Class_ in parseExpr_New; Zend @anonymous\\0path:line$id name (#4510, #6281 get_class/static::class)',
                'AOT: user AnonymousClass@* methods lowered when PHP_COMPILER_SELFHOST_AOT=1 (#3098)',
            ],
            'probe' => '$o = new class { public function f(): int { return 42; } }; echo $o->f();',
        ],
        [
            'id' => 'enum_declarations',
            'construct' => 'Enum declarations `enum Foo: string { case Bar = \'x\'; }`',
            'opcodes' => ['TYPE_DECLARE_ENUM', 'TYPE_DECLARE_CLASS_CONST', 'TYPE_CLASS_CONST_FETCH'],
            'issue' => 1356,
            'aot' => true,
            'notes' => [
                'Backed enum case objects with `->name` / `->value`; `echo $case` throws Error (#4891); double-quoted `"$case"` throws Error (#4785)',
                '`Foo::Bar` singleton fetch; `enum_exists` registry; `implements` interface list + instance methods + `instanceof` (#3373)',
                'static methods (#2299); `Enum::cases()` JIT (#3308, #4068); AOT fixture enum_backed.phpt (#3076)',
                '`BackedEnum::from()` / `tryFrom()` VM + JIT lookup with Zend-parity ValueError (#3114, #4053)',
            ],
            'probe' => 'interface L { public function n(): string; } enum S: string implements L { case A = "a"; public function n(): string { return $this->name; } } echo S::A->n();',
        ],
        [
            'id' => 'abstract_enum_declarations',
            'construct' => 'Abstract enum `abstract enum E { case A; }`',
            'opcodes' => ['TYPE_DECLARE_ENUM', 'TYPE_DECLARE_CLASS_CONST', 'TYPE_CLASS_CONST_FETCH'],
            'issue' => 3737,
            'notes' => ['Source rewriter + php-cfg flags; `new E()` fatals; case fetch works on VM'],
            'probe' => 'abstract enum E { case A; } echo E::A->name;',
        ],
        [
            'id' => 'instance_methods',
            'construct' => 'Instance methods (`ClassMethod` / `Expr_MethodCall`)',
            'opcodes' => ['TYPE_DECLARE_METHOD', 'TYPE_METHODCALL_INIT'],
            'issue' => 58,
            'notes' => ['MiniWebApp `$router->dispatch()` (#2059)'],
            'probe' => 'class C { public function f(): string { return "ok"; } } echo (new C())->f();',
        ],
        [
            'id' => 'static_methods',
            'construct' => 'Static methods on user classes (`Expr_StaticCall`)',
            'opcodes' => ['TYPE_DECLARE_METHOD', 'TYPE_STATICCALL_INIT', 'TYPE_FUNCCALL_EXEC_RETURN'],
            'issue' => 2209,
            'notes' => [
                'Public static methods without $this; `Router::fromConfig()` factory (#2059)',
                'Late static `static::method()` tracked separately (#1231)',
            ],
            'probe' => 'class C { public static function id(): string { return "ok"; } } echo C::id();',
        ],
        [
            'id' => 'construct_method',
            'construct' => 'Constructors (`__construct`)',
            'opcodes' => ['TYPE_DECLARE_METHOD', 'TYPE_NEW', 'TYPE_METHODCALL_INIT', 'TYPE_ARG_RECV'],
            'issue' => 145,
            'notes' => ['Router `__construct(array $config)` (#2059)'],
            'probe' => 'class C { private string $x; public function __construct(string $x = "a") { $this->x = $x; } public function get(): string { return $this->x; } } echo (new C())->get();',
        ],
        [
            'id' => 'clone_magic',
            'construct' => '`clone` + `__clone()` magic method',
            'opcodes' => ['TYPE_CLONE', 'TYPE_DECLARE_METHOD'],
            'issue' => 3170,
            'notes' => [
                'Zend zend_std_clone_object: shallow copy then __clone when defined',
                'VM invokePhpFunction; JIT invokeCloneMagicIfPresent after cloneObject',
            ],
            'probe' => 'class C { public int $x = 1; public function __clone() { $this->x = 2; } } $a = new C(); $b = clone $a; echo $b->x;',
        ],
        [
            'id' => 'clone_with',
            'construct' => 'PHP 8.3+ `clone $obj with { prop: $value }`',
            'opcodes' => ['TYPE_CLONE', 'TYPE_METHODCALL_INIT'],
            'issue' => 4513,
            'jit' => true,
            'notes' => [
                'Ast\\CloneWithDesugar before php-parser (#4513); lowers to IIFE clone + property writes',
                'Zend/zend_language_parser.y clone_expr with clause; zend_clones.c property overrides',
            ],
            'probe' => 'class C { public int $x = 1; public string $y = "a"; } $c = new C(); $d = clone $c with { x: 2, y: "b" }; echo $d->x, $d->y;',
        ],
        [
            'id' => 'magic_methods',
            'construct' => 'Magic methods `__get` / `__set` / `__call` / `__toString`',
            'opcodes' => [],
            'issue' => 146,
            'jit' => true,
            'notes' => [
                'Zend zend_object_handlers.c: zend_std_read_property, zend_std_write_property, zend_std_get_method, __toString cast',
                'VM slow path on undeclared property read/write and missing method call; JIT MagicMethodDispatch (#4022, #4066 dynamic $obj->$name); __callStatic (#3273) VM-only',
            ],
            'probe' => 'class M { function __get(string $k): string { return $k; } } echo (new M)->foo;',
        ],
        [
            'id' => 'private_methods',
            'construct' => 'Private methods',
            'opcodes' => ['TYPE_DECLARE_METHOD', 'TYPE_METHODCALL_INIT'],
            'issue' => 145,
            'notes' => ['Router private `render*` paths (#2059)'],
            'probe' => 'class C { private function f(): string { return "ok"; } public function g(): string { return $this->f(); } } echo (new C())->g();',
        ],
        [
            'id' => 'property_fetch',
            'construct' => 'Property fetch `$this->x`',
            'opcodes' => ['TYPE_PROPERTY_FETCH', 'TYPE_DECLARE_PROPERTY'],
            'issue' => 58,
            'notes' => [],
            'probe' => 'class C { public int $x = 1; public function f(): int { return $this->x; } } echo (new C())->f();',
        ],
        [
            'id' => 'ctor_promotion',
            'construct' => 'Constructor property promotion',
            'opcodes' => ['TYPE_DECLARE_PROPERTY', 'TYPE_PROPERTY_FETCH', 'TYPE_ASSIGN', 'TYPE_ARG_RECV'],
            'issue' => 1359,
            'notes' => ['Promoted params declare property + assign in __construct'],
            'probe' => 'class C { public function __construct(private string $x = "a") {} public function get(): string { return $this->x; } } echo (new C())->get();',
        ],
        [
            'id' => 'method_return_types',
            'construct' => 'Method return types (`: string` / `: void`)',
            'opcodes' => ['TYPE_DECLARE_METHOD', 'TYPE_RETURN', 'TYPE_RETURN_VOID'],
            'issue' => 55,
            'jit' => true,
            'notes' => ['#55 native `: string`/`: int`/`: bool`/`: float`/`: array`/`: ?T` LLVM returns; MCJIT execute #2055'],
            'probe' => 'class C { public function f(): string { return "ok"; } public function g(): void {} } echo (new C())->f();',
        ],
        [
            'id' => 'dynamic_property_fetch',
            'construct' => 'Dynamic property access `$obj->$name`',
            'opcodes' => ['TYPE_PROPERTY_FETCH', 'TYPE_DECLARE_PROPERTY'],
            'issue' => 1227,
            'notes' => ['JIT compares runtime name to declared properties; unknown names abort at runtime'],
            'probe' => 'class C { public int $x = 1; } $c = new C(); $k = "x"; echo $c->$k;',
        ],
        [
            'id' => 'variable_function_call',
            'construct' => 'Variable function call `$fn()`',
            'opcodes' => ['TYPE_FUNCCALL_INIT', 'TYPE_FUNCCALL_EXEC_RETURN', 'TYPE_FUNCCALL_EXEC_NORETURN'],
            'issue' => 56,
            'notes' => ['VM resolves callee at runtime; JIT when callee name is compile-time string in variable (#56)'],
            'probe' => '$fn = "strlen"; echo $fn("hi");',
        ],
        [
            'id' => 'native_user_class',
            'construct' => 'Native user-class link (`phpc build --project`)',
            'opcodes' => ['TYPE_DECLARE_CLASS', 'TYPE_NEW', 'TYPE_METHODCALL_INIT'],
            'issue' => 764,
            'notes' => ['AOT link yes (#568 closed); native execute ✅ (#764 closed)'],
            'probe' => null,
            'aot' => true,
        ],
        [
            'id' => 'instanceof',
            'construct' => '`instanceof`',
            'opcodes' => ['TYPE_INSTANCEOF'],
            'issue' => 138,
            'notes' => [],
            'probe' => 'class C {} echo ((new C()) instanceof C) ? "yes" : "no";',
        ],
        [
            'id' => 'instanceof_union',
            'construct' => '`instanceof` union RHS `(A|B)`',
            'opcodes' => ['TYPE_INSTANCEOF'],
            'issue' => 3461,
            'notes' => ['php-cfg parses BitwiseOr of class names from php-parser; VM + JIT union OR (#3461)'],
            'probe' => 'interface A {} interface B {} class C implements A, B {} echo ((new C) instanceof (A|B)) ? "1" : "0";',
        ],
        [
            'id' => 'in_operator',
            'construct' => 'PHP 8.3+ `in` operator (`$needle in $haystack`)',
            'opcodes' => ['TYPE_IN'],
            'issue' => 4682,
            'notes' => [
                'Ast\\InOperatorDesugar + InOperatorResolver (#4682); VM InOperator::contains (===)',
                'JIT TYPE_IN via ArrayBuiltinHelper::inArray strict (#4716); EnumInOperatorJitCompileTest',
            ],
            'probe' => 'enum E: string { case A = "a"; case B = "b"; } echo (E::A in [E::A, E::B]) ? "yes" : "no";',
        ],
        [
            'id' => 'match_expr',
            'construct' => '`match` expression',
            'opcodes' => ['TYPE_IDENTICAL', 'TYPE_JUMPIF', 'TYPE_ASSIGN'],
            'issue' => 143,
            'notes' => [
                'Lowered in php-cfg to === / jump-if / assign (#143)',
                'Wave-3 literal-arm subset (#2398); acceptance PHPT (#2428)',
                'Guard arms: expression patterns evaluated before === compare; nested match patterns (#3397); match_guard.phpt',
                'Enum case arms: === identity on case singletons, not backed scalar (#4274); match_enum_case.phpt',
                'Arm assignment side effects bind variables when arm matches (#3787); match_arm_assign.phpt',
            ],
            'probe' => 'echo match (2) { 1 => "a", 2 => "b", default => "c" };',
        ],
        [
            'id' => 'closures',
            'construct' => 'Closures `function () { }` / `use ($var)`',
            'opcodes' => ['TYPE_CLOSURE'],
            'issue' => 72,
            'aot' => true,
            'notes' => [
                'VM ClosureState + __invoke; Closure::bind/bindTo (#3266, #3673); JIT bindTo/bind + bound invoke via ClosureBindHelper (#4192)',
                'use() by-value and by-ref (#3081, #3108); array_map/filter/usort callbacks (#3086)',
                'JIT ClosureHelper: TYPE_CLOSURE + use() value/ref IR (#3092, #3108); use ($x) MCJIT snapshot via aliasVariableOpFromSlot (#2483); use (&$x) MCJIT via valueBoxAliasPtr (#72, #4625); indirect $arr[0]() via __closure_target (#3089, #3092)',
                'AOT user scripts: real ClosureHelper lowering via PHP_COMPILER_AOT_USER_SCRIPT (#3725); use (&$x) AOT fixture closure_use_byref.phpt (#2483); bootstrap spine still stubs null',
                'bin/jit.php MCJIT execute still probe-dependent (#98)',
            ],
            'probe' => '$f = function ($x) { return $x + 1; }; echo $f(2);',
        ],
        [
            'id' => 'arrow_functions',
            'construct' => 'Arrow functions `fn () =>`',
            'opcodes' => ['TYPE_CLOSURE'],
            'issue' => 142,
            'aot' => true,
            'notes' => ['Desugars to TYPE_CLOSURE (#142); VM + JIT + AOT same as closures (#3725)'],
            'probe' => '$f = fn ($x) => $x + 1; echo $f(2);',
        ],
        [
            'id' => 'generator_yield',
            'construct' => 'Generators (`yield` / `foreach`)',
            'opcodes' => ['TYPE_YIELD', 'TYPE_ITER_RESET', 'TYPE_ITER_VALID', 'TYPE_ITER_VALUE'],
            'issue' => 167,
            'jit' => true,
            'aot' => true,
            'notes' => [
                'VM GeneratorState + foreach; keyed yield (#3085)',
                'MCJIT/AOT resume lowering for nested generator funcs (#3074, #3115); script-scope yield blocked',
                'JIT linear `yield` + `yield from` array/generator delegation (#3074, #4014); try/catch in generator bodies via resume prefixes (#4069)',
                'see docs/generators-jit-aot.md',
                'AOT fixture generator_yield.phpt + generator_yield_keys.phpt',
            ],
            'probe' => 'function g() { yield 1; yield 2; } foreach (g() as $v) echo $v;',
        ],
        [
            'id' => 'fiber_suspend',
            'construct' => 'Fibers (`Fiber`, `Fiber::suspend()`, start/resume)',
            'opcodes' => [],
            'issue' => 3130,
            'jit' => true,
            'aot' => true,
            'notes' => [
                'VM FiberState + builtin Fiber class (#3130); php-src Zend/zend_fibers.c',
                'JIT/AOT resume lowering (#4019); MCJIT execute green for nested callbacks (#6437); script-scope suspend still VM-fallback via Block::containsFiberSuspendOpcodesInScriptScope (#4097)',
            ],
            'probe' => '$f = new Fiber(function (): void { Fiber::suspend(1); }); echo $f->start();',
        ],
        [
            'id' => 'class_name_const',
            'construct' => '`ClassName::class` / `static::class`',
            'opcodes' => ['TYPE_CLASS_CONST_FETCH'],
            'issue' => 740,
            'notes' => ['Compile-time class name string; related to __CLASS__ (#199)'],
            'probe' => 'class A {} echo A::class;',
        ],
        [
            'id' => 'class_member_const',
            'construct' => 'Class member constants `public` / `private` / `protected const`',
            'opcodes' => ['TYPE_DECLARE_CLASS_CONST', 'TYPE_CLASS_CONST_FETCH'],
            'issue' => 2199,
            'jit' => true,
            'notes' => [
                'MiniWebApp `Router::DEFAULT_CONTACT_NAME_MAX` (#2059)',
                'JIT: literal class + dynamic const name (#3150); runtime `$class::CONST` class operand (#4095)',
            ],
            'probe' => 'class C { private const M = 1; public function f(): int { return self::M; } } echo (new C())->f();',
        ],
        [
            'id' => 'typed_class_const',
            'construct' => 'PHP 8.3 typed class constants (`const array X = [1,2];`, `const string S = \'a\';`)',
            'opcodes' => ['TYPE_DECLARE_CLASS_CONST', 'TYPE_CLASS_CONST_FETCH', 'TYPE_ARRAY_DIM_FETCH'],
            'issue' => 3592,
            'notes' => [
                'Compile-time literal fold for array/scalar const values; typed mismatch is compile-time TypeError; JIT lowers immutable array constants (#3592)',
                'Typed trait constants enabled on 8.3+ target (#5993); rejected on 8.2 (Zend parse error parity, #5212)',
            ],
            'probe' => 'class C { public const array X = [1, 2]; } echo C::X[0];',
        ],
        [
            'id' => 'global_typed_constant',
            'construct' => 'PHP 8.3+ file/namespace typed constants (`const string X = \'a\';`)',
            'opcodes' => ['TYPE_DECLARE_GLOBAL_CONST', 'TYPE_CONST_FETCH', 'TYPE_ARRAY_DIM_FETCH'],
            'issue' => 7081,
            'notes' => [
                'GlobalTypedConstRewriter + PHPCfg marker for nikic/php-parser 4.x; compile-time type check reuses class-const path',
                'PHP 8.4 `final const` at file scope enabled on 8.4+ target (#9909); rejected below 8.4 like Zend parse error (#10324)',
            ],
            'probe' => 'const string X = "a"; echo X;',
        ],
        [
            'id' => 'typed_interface_const',
            'construct' => 'PHP 8.3 typed interface constants (`interface I { public const string X = \'a\'; }`)',
            'opcodes' => ['TYPE_DECLARE_INTERFACE', 'TYPE_DECLARE_CLASS_CONST', 'TYPE_CLASS_CONST_FETCH'],
            'issue' => 5980,
            'notes' => [
                'Implements/inheritance typed constant compatibility enforced at compile time (#5953, #5980).',
            ],
            'probe' => 'interface I { public const string X = "a"; } class C implements I {} echo C::X;',
        ],
        [
            'id' => 'class_const_object',
            'construct' => 'Class constants with `new` object expressions (PHP 8.3+)',
            'opcodes' => ['TYPE_DECLARE_CLASS_CONST', 'TYPE_NEW'],
            'issue' => 9850,
            'notes' => [
                'Zend zend_compile_const_expr allows top-level `new Class(...)` in class constants on 8.3+ (#9850)',
                'NewWithoutParensCompileCheck rejects bare `new` without `()` and `new` nested in arrays; VM ClassConstMaterializer + JIT immortal singleton (#3196)',
            ],
            'probe' => 'class C { public const X = new stdClass(); } var_export(C::X);',
        ],
        [
            'id' => 'late_static_binding',
            'construct' => 'Late static binding `static::method()` / `static::class`',
            'opcodes' => ['TYPE_STATICCALL_INIT', 'TYPE_CLASS_CONST_FETCH'],
            'issue' => 1231,
            'notes' => ['VM/JIT called-class propagation; parent::method/class/$prop and static:: LSB (#1858, #3093); child method override AOT fixture extends_method_override.phpt (#2483)'],
            'probe' => 'class C { public static function id(): string { return static::class; } } echo C::id();',
        ],
        [
            'id' => 'new_static_return_type',
            'construct' => '`new static()` and `: static` return type (late-bound class)',
            'opcodes' => ['TYPE_NEW', 'TYPE_RETURN'],
            'issue' => 3412,
            'notes' => ['VM resolveClassScopeName on TYPE_NEW; returnTypeStatic verify (#3412)'],
            'probe' => 'class B { public static function make(): static { return new static(); } } class C extends B {} echo get_class((new C())->make());',
        ],
        [
            'id' => 'magic_const_class_method',
            'construct' => 'Magic constants `__CLASS__`, `__METHOD__`, `__FUNCTION__`',
            'opcodes' => ['TYPE_CONST_FETCH'],
            'issue' => 199,
            'notes' => ['Lowered at parse time via php-cfg MagicStringResolver'],
            'probe' => 'class C { public function id(): string { return __CLASS__ . "::" . __FUNCTION__; } } echo (new C)->id();',
        ],
        [
            'id' => 'magic_const_namespace',
            'construct' => 'Magic constant `__NAMESPACE__`',
            'opcodes' => ['TYPE_CONST_FETCH'],
            'issue' => 199,
            'notes' => ['Requires `namespace` declaration (#84)'],
            'probe' => null,
        ],
        [
            'id' => 'magic_const_dir_file',
            'construct' => 'Magic constants `__DIR__`, `__FILE__`',
            'opcodes' => ['TYPE_SCRIPT_MAGIC', 'TYPE_INCLUDE'],
            'issue' => 707,
            'notes' => ['VM script stack on include; JIT uses per-unit script path'],
            'probe' => null,
        ],
        [
            'id' => 'magic_const_line',
            'construct' => 'Magic constant `__LINE__`',
            'opcodes' => ['TYPE_SCRIPT_MAGIC', 'TYPE_INCLUDE'],
            'issue' => 715,
            'notes' => ['Per-site line on TYPE_SCRIPT_MAGIC; include stack for multi-file units'],
            'probe' => null,
        ],
        [
            'id' => 'literal_include',
            'construct' => 'Literal `include`/`require` with `__DIR__`',
            'opcodes' => ['TYPE_INCLUDE'],
            'issue' => 475,
            'notes' => ['Compile-time inlining via IncludeHelper; two-file PHPT + MiniWebApp JIT gate (#587)'],
            'probe' => null,
        ],
        [
            'id' => 'foreach_by_ref',
            'construct' => 'foreach by-reference (`&$v`)',
            'opcodes' => ['TYPE_ITER_VALUE', 'TYPE_ASSIGN_REF'],
            'issue' => 1222,
            'jit' => true,
            'aot' => true,
            'notes' => [
                'Packed and string-keyed arrays; VM + JIT/AOT lowering (#1222, #4364)',
                'AOT: borrowed hashtable entry refs skip valueDelref (IteratorHelper)',
                'AOT PHPT: foreach_by_ref.phpt, foreach_by_ref_assoc.phpt',
            ],
            'probe' => '$a = [1, 2, 3]; foreach ($a as &$v) { $v *= 2; } echo $a[0], $a[1], $a[2];',
        ],
        [
            'id' => 'foreach_iterator',
            'construct' => 'foreach over Iterator / IteratorAggregate objects',
            'opcodes' => ['TYPE_ITER_RESET', 'TYPE_ITER_VALID', 'TYPE_ITER_KEY', 'TYPE_ITER_VALUE'],
            'issue' => 4067,
            'jit' => true,
            'aot' => true,
            'notes' => [
                'VM + JIT/AOT call rewind/valid/current/key/next (Zend zend_iterators.c parity)',
                'IteratorProtocolHelper + IteratorHelper (#4011); IteratorAggregate::getIterator()',
                'TypeError for non-iterable objects; compliance foreach_iterator_jit.phpt',
            ],
            'probe' => 'class T implements Iterator { private int $i = 0; public function current() { return $this->i; } public function key() { return $this->i; } public function next(): void { $this->i++; } public function rewind(): void { $this->i = 0; } public function valid(): bool { return $this->i < 2; } } $s = 0; foreach (new T() as $v) { $s += $v; } echo $s;',
        ],
        [
            'id' => 'array_access_interface',
            'construct' => 'ArrayAccess interface — `$obj[$key]` read/write/isset/unset',
            'opcodes' => ['TYPE_ARRAY_DIM_FETCH', 'TYPE_ARRAY_DIM_FETCH_WRITE', 'TYPE_ISSET', 'TYPE_UNSET'],
            'issue' => 3331,
            'jit' => true,
            'aot' => true,
            'notes' => [
                'VM dispatches offsetGet/Set/Exists/Unset (Zend zend_object_handlers.c read_dimension)',
                'JIT ArrayAccessHelper + RuntimeIndirectInstanceMethodCall (#4012)',
                'Non-ArrayAccess objects keep Illegal offset (abort)',
            ],
            'probe' => null,
        ],
        [
            'id' => 'ref_param',
            'construct' => 'By-reference parameters (`function f(&$x)`)',
            'opcodes' => ['TYPE_ARG_RECV', 'TYPE_ARG_SEND'],
            'issue' => 140,
            'notes' => [
                'VM TYPE_INDIRECT; JIT aliases caller __value__* via paramByRef (#3161)',
                'Return-by-ref `function &f()` + `$x = &f()` on VM (#3414)',
            ],
            'probe' => 'function inc(&$n) { $n++; } $x = 1; inc($x); echo $x;',
        ],
        [
            'id' => 'return_by_ref',
            'construct' => 'Return-by-reference (`function &f()` / `$x = &f()`)',
            'opcodes' => ['TYPE_RETURN', 'TYPE_FUNCCALL_EXEC_RETURN', 'TYPE_ASSIGN_REF'],
            'issue' => 4054,
            'jit' => true,
            'aot' => true,
            'notes' => ['VM + JIT propagate reference cells via FLAG_RETURNS_REF (#3414, #3778); AOT aliases __value__* return slots (#4054)'],
            'probe' => 'function &c() { static $n = 0; return $n; } $r = &c(); $r = 5; echo c();',
        ],
        [
            'id' => 'static_property_fetch',
            'construct' => 'Static property `Class::$prop`',
            'opcodes' => ['TYPE_STATIC_PROPERTY_FETCH', 'TYPE_DECLARE_STATIC_PROPERTY'],
            'issue' => 1225,
            'notes' => ['Class-scoped storage; `self::` / `static::`; runtime property name on JIT/AOT (#4597)'],
            'probe' => 'class C { public static int $n = 1; } echo C::$n;',
        ],
        [
            'id' => 'error_control_operator',
            'construct' => 'Error-control operator `@` on expressions',
            'opcodes' => ['TYPE_BEGIN_SILENCE', 'TYPE_END_SILENCE'],
            'issue' => 3546,
            'notes' => [
                'php-cfg ErrorSuppressBlock + Simplifier preserve (#3546)',
                'VM masks error_reporting; JIT/AOT __compiler_begin_silence / __compiler_end_silence (#4070)',
            ],
            'probe' => 'echo @$undefined; @trigger_error("x", E_USER_NOTICE); echo "ok\\n";',
        ],
        [
            'id' => 'unset',
            'construct' => '`unset()` on variables, array offsets, and object properties',
            'opcodes' => ['TYPE_UNSET', 'TYPE_STATIC_PROPERTY_UNSET'],
            'issue' => 2273,
            'notes' => [
                'Locals, `$this->prop`, public properties, string/int keys (#1224)',
                'Static properties via TYPE_STATIC_PROPERTY_UNSET (#2256)',
            ],
            'probe' => 'class C { public $p = 1; } $o = new C(); unset($o->p); echo isset($o->p) ? "y" : "n";',
        ],
        [
            'id' => 'function_static_local',
            'construct' => 'Function-local `static $var` / `static $var = <literal>`',
            'opcodes' => [
                'TYPE_DECLARE_FUNCTION_STATIC',
                'TYPE_JUMPIF_FUNCTION_STATIC_INITIALIZED',
                'TYPE_FUNCTION_STATIC_INIT_STORE',
            ],
            'issue' => 2286,
            'aot' => true,
            'notes' => [
                'Compile-time literal init (int/string/array) plus runtime constant init (`new`, etc.) — VM + JIT + AOT (#4352, #4027)',
                'Uninitialized `static $x;` with isset guard — Zend parity (#3533); `static &$x` is not valid PHP syntax (php-src `static_var` grammar)',
                'PHP 8.3+ typed function-local static (`static int $n = 0`) — marker rewrite + runtime TypeCheck (#9998)',
            ],
            'probe' => 'function f(){static $n=0; $n++; return $n;} echo f().f().f();',
        ],
        [
            'id' => 'typed_function_static',
            'construct' => 'PHP 8.3+ typed function-local static (`static T $var`)',
            'opcodes' => ['TYPE_DECLARE_FUNCTION_STATIC', 'TYPE_ASSIGN'],
            'issue' => 9998,
            'notes' => [
                'Source marker rewrite for php-parser 4.x; VM enforces declared type on writes (#9998)',
            ],
            'probe' => 'function f(): void { static int $n = 0; $n++; echo $n; } f(); f();',
        ],
        [
            'id' => 'keyed_list_destruct',
            'construct' => 'Keyed array destructuring (`["a" => $x]`)',
            'opcodes' => ['TYPE_INIT_ARRAY', 'TYPE_ADD_ARRAY_ELEMENT', 'TYPE_ARRAY_DIM_FETCH', 'TYPE_ASSIGN'],
            'issue' => 1234,
            'notes' => ['Skip string-key CFG split for fetch+assign destructuring pairs (#1234)'],
            'probe' => '["a" => $x, "b" => $y] = ["a" => 1, "b" => 2]; echo $x, $y;',
        ],
        [
            'id' => 'goto',
            'construct' => '`goto` / labels (function scope)',
            'opcodes' => ['TYPE_JUMP'],
            'issue' => 1228,
            'notes' => [
                'php-cfg lowers labels to CFG Jump; VM avoids frame nesting on same-block back-edges',
                'AOT native execute via TYPE_JUMP lowering (issue #4042)',
            ],
            'probe' => '$i = 0; start: $i++; if ($i < 2) { goto start; } echo $i;',
        ],
        [
            'id' => 'strict_types',
            'construct' => '`declare(strict_types=1)` scalar parameter checks',
            'opcodes' => ['TYPE_ARG_RECV', 'TYPE_FUNCCALL_EXEC_RETURN'],
            'issue' => 1229,
            'notes' => ['VM #156; JIT enforces at user call sites via JIT\\TypeCheck + Native::compileArg weak casts'],
            'probe' => 'declare(strict_types=1); function f(int $x) { return $x; } echo f(1);',
        ],
        [
            'id' => 'array_type_list',
            'construct' => 'PHP 8.3+ generic array types `list<T>` / `array<K,V>` (parameters and properties)',
            'opcodes' => ['TYPE_ARG_RECV', 'TYPE_DECLARE_PROPERTY', 'TYPE_ASSIGN'],
            'issue' => 3705,
            'notes' => [
                'Source rewrite to magic identifier types for php-parser v4; VM list shape check (#3705)',
                'Zend generic-array RFC not merged in php-src 8.4 — parity target is proposed list/array forms',
            ],
            'probe' => 'function f(list $x): void {} f([1]);',
        ],
        [
            'id' => 'variable_variables',
            'construct' => 'Variable variables (`$$name`)',
            'opcodes' => ['TYPE_VAR_FETCH'],
            'issue' => 1226,
            'notes' => ['php-cfg nests Operand\\Variable name; VM resolves runtime local by name; JIT compile-time name fold (#1226)'],
            'probe' => '$a = "x"; $x = 1; echo $$a;',
        ],
        [
            'id' => 'variable_function_calls',
            'construct' => 'Variable function calls (`$fn()`)',
            'opcodes' => ['TYPE_FUNCCALL_INIT', 'TYPE_FUNCCALL_EXEC_RETURN'],
            'issue' => 56,
            'notes' => ['VM resolves callee at runtime; compiler folds literal assignment; JIT uses compile-time string'],
            'probe' => '$fn = "strlen"; echo $fn("hi");',
        ],
        [
            'id' => 'invoke_object',
            'construct' => 'Invokable objects (`$obj()` / `__invoke`)',
            'opcodes' => ['TYPE_METHODCALL_INIT', 'TYPE_DECLARE_METHOD'],
            'issue' => 1232,
            'notes' => ['Object-typed FuncCall lowered to __invoke method dispatch; VM runtime fallback'],
            'probe' => 'class C { public function __invoke(): int { return 1; } } echo (new C())();',
        ],
        [
            'id' => 'first_class_callable',
            'construct' => 'First-class callable syntax (`foo(...)`, `Class::m(...)`)',
            'opcodes' => ['TYPE_ASSIGN', 'TYPE_FUNCCALL_INIT'],
            'issue' => 1363,
            'notes' => [
                'php-cfg Expr_FirstClassCallable (#1230); VM/JIT TYPE_FROM_CALLABLE → Closure (#4810)',
                'Instance method $obj->m(...) VM + JIT bound [object, method] array callable (#3566, #4040)',
                'JIT folds strlen(...) / Class::m(...) via compileTimeString assign chains (#1363)',
                'php-types TypeReconstructor patch for Expr_FirstClassCallable (#2315)',
            ],
            'probe' => '$fn = strlen(...); echo $fn("x");',
        ],
        [
            'id' => 'pipe_operator',
            'construct' => 'PHP 8.5 pipe operator (`|>`)',
            'opcodes' => ['TYPE_FUNCCALL_INIT', 'TYPE_FUNCCALL_EXEC_RETURN'],
            'issue' => 7219,
            'notes' => [
                'Ast\\PipeOperatorDesugar before php-parser (#3243); lowers $lhs |> f(...) to f($lhs, ...)',
                'Bare callable names: $lhs |> strlen → strlen($lhs) (#7219)',
                'PHP 8.5 errata: arrow-fn RHS must be parenthesized — $lhs |> (fn($p) => expr) (#7219, php-src #19533)',
                'Chained pipes and parenthesized-callable LHS (#6705, #7219)',
                'Zend/zend_compile.c pipe expression; requires first-class callable (#1363)',
            ],
            'probe' => 'echo "hi" |> strtoupper(...);',
        ],
        [
            'id' => 'use_function_const_import',
            'construct' => '`use function` / `use const` imports',
            'opcodes' => ['TYPE_FUNCCALL_INIT', 'TYPE_CONST_FETCH'],
            'issue' => 2325,
            'notes' => [
                'php-cfg resolves imported names at parse time; no Stmt_Use lowering required',
            ],
            'probe' => 'namespace N { function f() { return 1; } } namespace U { use function N\\f; echo f(); }',
        ],
        [
            'id' => 'use_group_import',
            'construct' => 'Namespace group use (`use Foo\\{Bar, Baz}`)',
            'opcodes' => ['TYPE_FUNCCALL_INIT', 'TYPE_CONST_FETCH', 'TYPE_CLASS_CONST_FETCH'],
            'issue' => 2443,
            'notes' => [
                'PhpParser Stmt_GroupUse; NameResolver registers aliases; GroupUseStripper before PHPCfg',
            ],
            'probe' => 'namespace N { class A {} } namespace U { use N\\{A}; echo (new A()) ? 1 : 0; }',
        ],
        [
            'id' => 'heredoc_flexible_indent',
            'construct' => 'Flexible heredoc/nowdoc indentation stripping (PHP 7.3+)',
            'opcodes' => [],
            'issue' => 3636,
            'notes' => [
                'php-parser Emulative FlexibleDocStringEmulator + parseDocString stripIndentation (#3636)',
                'Indented closing label sets docIndentation column; basic heredoc/nowdoc #178',
                'Zend/zend_language_scanner.l flexible heredoc/nowdoc parity',
            ],
            'probe' => "echo <<<EOT\n    hello\n    EOT;",
        ],
        [
            'id' => 'never_return',
            'construct' => '`never` return type',
            'opcodes' => ['TYPE_EXIT', 'TYPE_RETURN', 'TYPE_RETURN_VOID'],
            'issue' => 1358,
            'notes' => ['php-cfg Op\\Type\\Never_; any `return` in body is a compile error; normal completion via throw/exit'],
            'probe' => 'function f(): never { exit("x"); } f();',
        ],
        [
            'id' => 'intersection_types',
            'construct' => 'Intersection types (`A&B`)',
            'opcodes' => ['TYPE_DECLARE_INTERFACE', 'TYPE_DECLARE_CLASS', 'TYPE_ARG_RECV'],
            'issue' => 1357,
            'notes' => ['php-cfg Op\\Type\\Intersection; VM checks object implements each interface at call'],
            'probe' => 'interface A {} interface B {} class C implements A, B {} function f(A&B $x): int { return 1; } f(new C());',
        ],
        [
            'id' => 'dnf_types',
            'construct' => 'DNF types (`(A&B)|null`, union of intersections)',
            'opcodes' => ['TYPE_DECLARE_INTERFACE', 'TYPE_DECLARE_CLASS', 'TYPE_DECLARE_PROPERTY', 'TYPE_ARG_RECV'],
            'issue' => 3094,
            'notes' => [
                'php-cfg Union + Intersection; Type::fromTypeDecl + TypeReconstructor resolveOpType for Union_/Intersection (#4106)',
                'TYPE_DECLARE_PROPERTY / promotion: compileTypeConstrainedVariable sets dnfArms; VM DnfCheck on property writes',
                'JIT/AOT DnfParamCheck at call sites, returns, and property writes (#4111); __value__* param ABI (#4008)',
                'Parenthesized DNF only (php-parser 4.x); ref Zend/zend_compile.c zend_compile_type',
            ],
            'probe' => 'interface A {} interface B {} class C implements A, B {} class H { public (A&B)|null $x; } $h = new H(); $h->x = new C(); echo $h->x === null ? 0 : 1;',
            'jit' => true,
            'aot' => true,
        ],
        [
            'id' => 'trait_decl',
            'construct' => '`trait` declarations with method bodies',
            'opcodes' => ['TYPE_DECLARE_TRAIT', 'TYPE_DECLARE_METHOD'],
            'issue' => 2312,
            'notes' => [
                'VM registers traits in class table with isTrait; trait use in classes is #144',
                'interface_exists/trait_exists distinguish from class_exists',
            ],
            'probe' => 'trait T { public function m(): int { return 1; } } echo trait_exists(T::class) ? 1 : 0;',
        ],
        [
            'id' => 'trait_use_simple',
            'construct' => 'Simple `use Trait;` in class body',
            'opcodes' => ['TYPE_DECLARE_CLASS', 'TYPE_USE_TRAIT', 'TYPE_TRAIT_USE_ADAPTATION', 'TYPE_DECLARE_METHOD'],
            'issue' => 2314,
            'jit' => true,
            'aot' => true,
            'notes' => [
                'php-cfg-trait-use.patch; VM merges trait methods into class',
                'JIT/AOT alias trait-merged methods onto using class (#3789)',
                'TraitUseAdaptation alias/insteadof/visibility on VM (#3238, #144)',
                'Horizontal trait method collision fatals at compile time (#3416)',
            ],
            'probe' => 'trait T { public function m(): int { return 1; } } class C { use T; } echo (new C())->m();',
        ],
        [
            'id' => 'trait_use_adaptation',
            'construct' => 'Trait use adaptations (`as` rename, `insteadof` precedence)',
            'opcodes' => ['TYPE_USE_TRAIT', 'TYPE_TRAIT_USE_ADAPTATION'],
            'issue' => 3238,
            'jit' => true,
            'aot' => true,
            'notes' => [
                'Zend/zend_compile.c trait alias/precedence; VM applyTraitUsesWithAdaptations',
                'JIT/AOT batch trait use with insteadof/as (#2483, #3238)',
                'Visibility `as private|protected` on trait alias — VM/JIT/AOT (#144, #2483)',
            ],
            'probe' => 'trait T { public function f(): int { return 1; } } class C { use T { f as r; } } echo (new C())->r();',
        ],
        [
            'id' => 'lazy_ghost_trait',
            'construct' => 'Built-in `LazyGhostTrait` marker (PHP 8.4 lazy objects)',
            'opcodes' => ['TYPE_DECLARE_CLASS', 'TYPE_USE_TRAIT'],
            'issue' => 6096,
            'notes' => [
                'Zend/zend_lazy_objects.c — internal trait; not in get_declared_traits/trait_exists (#7009)',
                'Compiler allows use LazyGhostTrait in class bodies; ReflectionClass::newLazyGhost (#5968)',
            ],
            'probe' => 'echo count(get_declared_traits()); echo trait_exists("LazyGhostTrait") ? 1 : 0;',
        ],
        [
            'id' => 'array_argument_unpack',
            'construct' => 'Array/argument unpack `...$x`',
            'opcodes' => ['TYPE_ARRAY_SPREAD', 'TYPE_ARG_SEND', 'TYPE_INIT_ARRAY', 'TYPE_ADD_ARRAY_ELEMENT'],
            'issue' => 1361,
            'notes' => ['php-cfg spread.patch (#141); VM HashTable::spreadFrom; JIT HashTableHelper::spreadInto + mergeCallArgEntries'],
            'probe' => '$a = [1, 2]; $b = [...$a, 3]; function s(...$n) { return count($n); } s(...$a);',
        ],
        [
            'id' => 'serialize_magic',
            'construct' => '`__serialize` / `__unserialize` magic methods',
            'opcodes' => ['TYPE_DECLARE_METHOD', 'TYPE_METHODCALL_INIT', 'TYPE_FUNCCALL_EXEC_RETURN'],
            'issue' => 1365,
            'notes' => ['serialize()/unserialize() call __serialize/__unserialize when present; VM via VmSerialize (#3368)'],
            'probe' => 'class B { private int $n = 0; public function __construct(int $n = 0) { $this->n = $n; } public function __serialize(): array { return ["n" => $this->n]; } public function __unserialize(array $d): void { $this->n = $d["n"]; } public function get(): int { return $this->n; } } $r = unserialize(serialize(new B(3))); echo $r->get();',
        ],
        [
            'id' => 'multi_catch',
            'construct' => 'Multi-type catch `catch (A|B $e)`',
            'opcodes' => ['TYPE_TRY', 'TYPE_CATCH', 'TYPE_THROW'],
            'issue' => 1362,
            'notes' => ['php-cfg records union types per catch; VM filters TYPE_CATCH via OpCode.catchTypes'],
            'probe' => 'class A {} class B {} try { throw new A(); } catch (A|B $e) { echo "ok"; }',
        ],
        [
            'id' => 'try_catch_throw',
            'construct' => '`try` / `catch` / `throw`',
            'opcodes' => ['TYPE_TRY', 'TYPE_CATCH', 'TYPE_THROW'],
            'issue' => 57,
            'notes' => [
                'throw lowering #195; php-cfg TryCatch overlay (#2084); VM TYPE_TRY/CATCH/THROW/FINALLY',
                'VM finally-before-catch + return-through-finally (#3081, #3106); TryCatchComplianceTest (10 tests)',
                'JIT TryCatchHelper IR verify (#3107); bin/jit.php VM fallback via requiresVmLowering (#2114); MCJIT execute probe TryCatchJitExecuteTest',
            ],
            'probe' => 'class E {} try { throw new E(); } catch (E $e) { echo "ok"; }',
        ],
        [
            'id' => 'throw_expression',
            'construct' => 'throw expressions (PHP 8.0) — `throw` in expression context',
            'opcodes' => ['TYPE_THROW', 'TYPE_NEW', 'TYPE_JUMPIF'],
            'issue' => 3802,
            'jit' => true,
            'aot' => true,
            'notes' => [
                'php-cfg Op\\Expr\\Throw_ overlay (#3802); php-types Expr_Throw type reconstructor patch',
                'Compiler: skip duplicate New before Throw; fresh slot for ?: merge; ?? RHS via compileThrowExpression',
                'VM/JIT reuse TYPE_THROW; compliance throw_expression.phpt (?:, ??, &&)',
                'AOT: splitMergeBeforeNestedTry for sequential try merge (#4041); JitThrow LLVM pending globals in standalone',
            ],
            'probe' => 'try { echo (false ? 1 : throw new LogicException("x")); } catch (LogicException $e) { echo $e->getMessage(); }',
        ],
        [
            'id' => 'readonly_class',
            'construct' => 'readonly classes',
            'opcodes' => ['TYPE_DECLARE_CLASS', 'TYPE_NEW', 'TYPE_ASSIGN', 'TYPE_PROPERTY_FETCH'],
            'issue' => 1360,
            'jit' => true,
            'aot' => true,
            'notes' => [
                'php-cfg Class_::flags MODIFIER_READONLY; VM rejects instance property writes after __construct',
                'JIT ReadonlyClassGuard + readonlyClassIds; bin/jit.php VM fallback via containsReadonlyClassOpcodes (#4082)',
                'AOT pending raise + phpc_jit_abort_if_pending_logic_exception; compliance readonly_class_jit.phpt',
                'php-src: Zend/zend_object_handlers.c readonly class write guard',
            ],
            'probe' => 'readonly class R { public function __construct(public int $x) {} } $o = new R(0); $o->x = 1;',
        ],
        [
            'id' => 'readonly_property',
            'construct' => 'readonly properties (per-property)',
            'opcodes' => ['TYPE_DECLARE_PROPERTY', 'TYPE_NEW', 'TYPE_ASSIGN', 'TYPE_PROPERTY_FETCH'],
            'issue' => 3149,
            'jit' => true,
            'aot' => true,
            'notes' => [
                'php-cfg readonly / propertyFlags MODIFIER_READONLY; VM/JIT reject writes and unset() after __construct',
                'JIT ReadonlyClassGuard IR + ReadonlyPropertyTest; MCJIT execute + compliance phpt',
                'php-src: Zend/zend_object_handlers.c zend_std_write_property / zend_std_unset_property',
            ],
            'probe' => 'class C { public readonly int $x; public function __construct() { $this->x = 1; } } $c = new C(); $c->x = 2;',
        ],
        [
            'id' => 'property_hooks',
            'construct' => 'Property hooks (`get` / `set` on properties)',
            'opcodes' => ['TYPE_ASSIGN', 'TYPE_PROPERTY_FETCH', 'TYPE_DECLARE_METHOD'],
            'issue' => 3145,
            'jit' => true,
            'aot' => true,
            'notes' => [
                'SourcePreprocessor lowers hooks to __phpc_property_* methods (#3145)',
                'Promoted constructor parameters with hook blocks preprocess to promoted param + __phpc_property_* methods (#7313)',
                'VM dispatches set/get on property access; JIT PropertyHookDispatch (#3723)',
                'Static property hooks rejected at compile time (#6619, php-src 8.4 zend_compile.c)',
                'AOT: user-class hook methods lower under PHP_COMPILER_SELFHOST_AOT; set-hook smoke in property_hook_set.phpt',
                'JIT: raw backing access in hook bodies via jitPropertyHookRawProperty (set + get methods, #4025, #4205)',
            ],
            'probe' => 'class U { public string $e { set (string $v) { $this->e = $v; } } } $o = new U(); $o->e = "a@b"; echo $o->e;',
        ],
        [
            'id' => 'asymmetric_visibility',
            'construct' => 'PHP 8.4 asymmetric property visibility (private(set), protected(set), etc.)',
            'opcodes' => ['TYPE_DECLARE_PROPERTY', 'TYPE_PROPERTY_FETCH', 'TYPE_ASSIGN'],
            'issue' => 3165,
            'jit' => true,
            'notes' => [
                'Ast\\AsymmetricVisibilityRewriter normalizes private(set) for php-parser 4.x; VM/JIT enforce set visibility with catchable Error (#3165, #4020)',
                'php-src: Zend/zend_compile.c ZEND_ACC_*_SET; AsymmetricVisibilityGuard + AsymmetricVisibilityJitCompileTest (#4020)',
            ],
            'probe' => 'class D { private(set) string $n = "x"; } $d = new D(); echo $d->n; $d->n = "y";',
        ],
        [
            'id' => 'class_destruct',
            'construct' => 'User __destruct()',
            'opcodes' => ['TYPE_DECLARE_CLASS', 'TYPE_DECLARE_METHOD', 'TYPE_NEW'],
            'issue' => 4096,
            'jit' => true,
            'aot' => true,
            'notes' => [
                'VM refcount + shutdown pass (#3144); unset/end-of-scope invoke before later statements (#4096)',
                'JIT/AOT: __object__invoke_destructor + phpc_destruct_try_invoke on delref; shutdown via phpc_gc_run_shutdown_destructors (#4013)',
                'AOT unset($o) before later statements verified (destruct_unset_echo_4096.php, class_destruct_user.phpt)',
                'Cyclic graphs: Zend defers __destruct until gc_collect_cycles(); refcount-zero path runs immediately — see #4023 for gc JIT/AOT',
                'MCJIT execute deferred when harness MCJIT unstable (#98)',
            ],
            'probe' => 'class D { function __destruct() { echo "bye"; } } new D();',
        ],
        [
            'id' => 'php8_attribute_reflection',
            'construct' => 'PHP 8 attributes — `ReflectionClass` / `ReflectionMethod` metadata',
            'opcodes' => ['TYPE_DECLARE_CLASS', 'TYPE_DECLARE_METHOD'],
            'issue' => 1936,
            'notes' => [
                'php-cfg preserves `attrGroups`; compiler stores attribute class names on TYPE_DECLARE_CLASS / TYPE_DECLARE_METHOD',
                'VM reflection reads from VM ClassEntry; JIT/AOT mirror class+method attribute tables into VMContext for reflection',
                'Read path: `getAttributes()` count + `ReflectionAttribute::getName()` + `newInstance()` with compile-time ctor args (#3206, #3216)',
                'Parameter/property reflection attributes still deferred; JIT `newInstance()` deferred (#2467)',
            ],
            'probe' => '#[\AllowDynamicProperties] class B {} $a = (new ReflectionClass(B::class))->getAttributes(); echo count($a).$a[0]->getName();',
        ],
        [
            'id' => 'weak_reference_weak_map',
            'construct' => 'WeakReference / WeakMap',
            'opcodes' => [],
            'issue' => 3667,
            'jit' => true,
            'notes' => [
                'VM: WeakReference::create/get via indirect target slot; unset clears get immediately',
                'GC-backed weak refs via WeakRefRegistry — referent collected by gc_collect_cycles() clears get()',
                'WeakMap uses object-id string keys; entries removed when key object is collected',
                'JIT: WeakRefRegistryRuntime LLVM registry (#3667, #5684)',
            ],
            'probe' => 'class Box {} $o = new Box(); $r = WeakReference::create($o); unset($o); echo $r->get() === null ? "1" : "0";',
        ],
        [
            'id' => 'heredoc_nowdoc',
            'construct' => 'Heredoc / nowdoc string literals (`<<<LABEL` / `<<<\'LABEL\'`)',
            'opcodes' => ['TYPE_CONCAT', 'TYPE_ASSIGN'],
            'issue' => 3187,
            'notes' => [
                'php-cfg Scalar_Encapsed → ConcatList; plain heredoc/nowdoc → Scalar_String (#178)',
                'PhpParser FlexibleDocStringEmulator (PHP 7.3+ flexible closing labels)',
                'php-src Zend/zend_compile.c zend_compile_encapsed_string',
            ],
            'probe' => <<<'PROBE'
$n = "w"; echo <<<H
{$n}
H;
PROBE,
        ],
        [
            'id' => 'datetime_oop',
            'construct' => 'DateTime / DateTimeZone OOP',
            'opcodes' => ['TYPE_NEW'],
            'issue' => 3072,
            'jit' => false,
            'aot' => false,
            'notes' => [
                'VM builtins: DateTime::__construct/format/getTimestamp/setTimezone; DateTimeZone::__construct/getName/getOffset/getLocation',
                'Host PHP date extension for parsing/formatting; UTC and named timezone subset (php-src ext/date/php_datetime.c)',
                'JIT/AOT method bodies VM-only in phase 1',
            ],
            'probe' => '$dt = new DateTime("2026-05-29", new DateTimeZone("UTC")); echo $dt->format("Y-m-d");',
        ],
    ];
}

/** @return array{vm: array<string, true>, jit: array<string, true>} */
function collectOpcodeHandlers(string $root): array
{
    $vm = opcodesHandledIn((string) file_get_contents($root . '/lib/VM.php'));
    $jitMain = opcodesHandledIn((string) file_get_contents($root . '/lib/JIT.php'));
    $jitPre = is_file($root . '/lib/JIT.pre')
        ? opcodesHandledIn((string) file_get_contents($root . '/lib/JIT.pre'))
        : [];
    $jit = $jitMain + $jitPre;

    return ['vm' => $vm, 'jit' => $jit];
}

/** @return array<string, true> */
function opcodesHandledIn(string $source): array
{
    $handled = [];
    if (preg_match_all('/case OpCode::(TYPE_[A-Z0-9_]+):/', $source, $matches)) {
        foreach ($matches[1] as $name) {
            $handled[$name] = true;
        }
    }

    return $handled;
}

function opcodesSupported(array $handled, array $required): bool
{
    foreach ($required as $opcode) {
        if (!isset($handled[$opcode])) {
            return false;
        }
    }

    return true;
}

function probeCompile(string $code, int $mode): bool
{
    try {
        $runtime = new PHPCompiler\Runtime($mode);
        $block = $runtime->parseAndCompile('<?php ' . $code, 'syntax-probe.php');

        return $block !== null;
    } catch (\Throwable) {
        return false;
    }
}

function probeVmCompile(string $code): bool
{
    return probeCompile($code, PHPCompiler\Runtime::MODE_NORMAL);
}

function probeAotCompile(string $code): bool
{
    return probeCompile($code, PHPCompiler\Runtime::MODE_AOT);
}

/**
 * @param list<array{id: string, construct: string, opcodes: list<string>, issue: int, notes: list<string>, probe: ?string, aot?: bool}> $definitions
 * @return list<array{construct: string, vm: bool, jit: bool, aot: bool, issue: int, notes: list<string>}>
 */
function collectSyntaxCapabilities(string $root, array $definitions, array $handlers): array
{
    $phpt = collectSyntaxPhptCoverage($root, $definitions);
    $rows = [];

    foreach ($definitions as $def) {
        $opcodeDriven = $def['opcodes'] !== [];
        $vm = $opcodeDriven
            ? opcodesSupported($handlers['vm'], $def['opcodes'])
            : (is_string($def['probe']) && $def['probe'] !== '' && probeVmCompile($def['probe']));
        if (array_key_exists('jit', $def)) {
            $jit = $def['jit'];
        } else {
            $jit = $opcodeDriven
                ? opcodesSupported($handlers['jit'], $def['opcodes'])
                : $vm;
        }
        if (array_key_exists('aot', $def)) {
            $aot = $def['aot'];
        } elseif (is_string($def['probe']) && $def['probe'] !== '') {
            $aot = probeAotCompile($def['probe']);
        } else {
            $aot = $jit;
        }
        $notes = $def['notes'];
        foreach ($phpt[$def['id']] ?? [] as $tag) {
            $notes[] = $tag;
        }
        if (!$jit && $vm) {
            $notes[] = 'VM-only lowering';
        }
        $rows[] = [
            'construct' => $def['construct'],
            'vm' => $vm,
            'jit' => $jit,
            'aot' => $aot,
            'issue' => $def['issue'],
            'notes' => array_values(array_unique($notes)),
        ];
    }

    return $rows;
}

/**
 * @param list<array{id: string, construct: string, opcodes: list<string>, issue: int, notes: list<string>, probe: ?string, aot?: bool}> $definitions
 * @return array<string, list<string>>
 */
function collectSyntaxPhptCoverage(string $root, array $definitions): array
{
    $coverage = [];
    foreach ($definitions as $def) {
        $coverage[$def['id']] = [];
    }

    $patterns = [
        'class_new' => '/\b(?:class\s+\w+|new\s+\w+)/',
        'instance_methods' => '/function\s+\w+\s*\(/',
        'construct_method' => '/function\s+__construct\s*\(/',
        'clone_magic' => '/function\s+__clone\s*\(/',
        'clone_with' => '/\bclone\b[^;{]*\bwith\s*\{/',
        'magic_methods' => '/function\s+__get\s*\(|function\s+__call\s*\(|function\s+__toString\s*\(/',
        'private_methods' => '/\bprivate\s+function\b/',
        'method_return_types' => '/function\s+\w+\([^)]*\)\s*:\s*(?:string|void|int)/',
        'property_fetch' => '/\$this->\w+/',
        'dynamic_property_fetch' => '/->\$/',
        'native_user_class' => '/\b(?:class\s+\w+|new\s+\w+)/',
        'instanceof' => '/\binstanceof\b/',
        'in_operator' => '/(?<![\w\$])in(?![\w\$])/',
        'match_expr' => '/\bmatch\s*\(/',
        'closures' => '/function\s*\([^)]*\)\s*(?:use\s*\([^)]*\)\s*)?\{/',
        'arrow_functions' => '/\bfn\s*\(/',
        'generator_yield' => '/\byield\b/',
        'class_name_const' => '/::class\b/',
        'enum_declarations' => '/\benum\s+\w+/',
        'class_member_const' => '/\b(?:public|private|protected)\s+const\b/',
        'magic_const_class_method' => '/__CLASS__|__FUNCTION__|__METHOD__/',
        'magic_const_namespace' => '/__NAMESPACE__/',
        'magic_const_dir_file' => '/__DIR__|__FILE__/',
        'magic_const_line' => '/__LINE__/',
        'goto' => '/\bgoto\s+\w+/',
        'variable_variables' => '/\$\$/',
        'array_argument_unpack' => '/\.\.\.\s*\$/',
        'multi_catch' => '/catch\s*\([^)]*\|/',
        'try_catch_throw' => '/\btry\s*\{/',
        'throw_expression' => '/\?\s*:\s*throw\b|\?\?\s*throw\b|&&\s*throw\b|\|\|\s*throw\b/',
        'heredoc_flexible_indent' => '/<<<\s*\w+\s*\r?\n\s+\S/',
        'array_access_interface' => '/implements\s+ArrayAccess|function\s+offsetGet\s*\(/',
        'heredoc_nowdoc' => '/<<<[\'"]?\w+/',
        'typed_interface_const' => '/interface\s+\w+\s*\{[^}]*\bconst\s+\w+\s+\w+\s*=|interface\s+\w+\s*\{[^}]*\b(?:public|protected|private)\s+const\s+\w+\s+\w+\s*=/s',
    ];

    $scan = [];
    $compliance = $root . '/test/compliance/cases';
    if (is_dir($compliance)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($compliance));
        foreach ($it as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.phpt')) {
                $scan[] = $file->getPathname();
            }
        }
    }
    foreach (glob($root . '/test/bootstrap-aot/*.php') ?: [] as $php) {
        $scan[] = $php;
    }
    $aotFixtures = $root . '/test/fixtures/aot/cases';
    if (is_dir($aotFixtures)) {
        foreach (glob($aotFixtures . '/*.phpt') ?: [] as $phpt) {
            $scan[] = $phpt;
        }
    }

    foreach ($scan as $path) {
        $content = (string) file_get_contents($path);
        $isPhpt = str_ends_with($path, '.phpt');
        $body = $isPhpt && preg_match('/--FILE--\s*\n<\?php(.*?)(?:--EXPECT|$)/s', $content, $m)
            ? $m[1]
            : $content;
        $aotPhpt = $isPhpt && str_contains(str_replace('\\', '/', $path), '/test/fixtures/aot/');
        $tag = $aotPhpt ? 'AOT PHPT' : ($isPhpt ? 'compliance PHPT' : 'bootstrap AOT');
        foreach ($patterns as $id => $pattern) {
            if (preg_match($pattern, $body)) {
                $coverage[$id][] = $tag;
            }
        }
    }

    foreach ($coverage as $id => $tags) {
        $coverage[$id] = array_values(array_unique($tags));
    }

    return $coverage;
}

/**
 * @param list<array{construct: string, vm: bool, jit: bool, aot: bool, issue: int, notes: list<string>}> $syntax
 */
function renderSyntaxMarkdown(array $syntax): string
{
    $lines = [
        '# Language construct capability matrix',
        '',
        'Auto-generated by `script/capability-syntax.php`. Do not edit by hand.',
        '',
        'User-defined classes, methods, visibility, `instanceof`, `match`, and arrow functions.',
        'Builtin functions are in [capabilities.md](capabilities.md).',
        '',
        'Tracking issues: [#58](' . CAPABILITY_ISSUE_URL_BASE . '58), [#145](' . CAPABILITY_ISSUE_URL_BASE
        . '145), [#138](' . CAPABILITY_ISSUE_URL_BASE . '138), [#568](' . CAPABILITY_ISSUE_URL_BASE
        . '568) (link closed), [#764](' . CAPABILITY_ISSUE_URL_BASE . '764) (execute closed), [#143]('
        . CAPABILITY_ISSUE_URL_BASE . '143), [#142](' . CAPABILITY_ISSUE_URL_BASE . '142), [#199]('
        . CAPABILITY_ISSUE_URL_BASE . '199).',
        '',
        '| Construct | VM | JIT | AOT | Issue | Notes |',
        '|-----------|:--:|:---:|:---:|-------|-------|',
    ];

    foreach ($syntax as $row) {
        $lines[] = sprintf(
            '| %s | %s | %s | %s | [#%d](%s%d) | %s |',
            $row['construct'],
            capabilityYesNo($row['vm']),
            capabilityYesNo($row['jit']),
            capabilityYesNo($row['aot']),
            $row['issue'],
            CAPABILITY_ISSUE_URL_BASE,
            $row['issue'],
            $row['notes'] === [] ? '' : implode('; ', $row['notes'])
        );
    }

    $lines[] = '';
    $lines[] = '_Syntax AOT column reflects `Runtime::MODE_AOT` compile probes unless a row pins AOT (e.g. native user-class link)._';
    $lines[] = '';

    return implode("\n", $lines);
}

function capabilityYesNo(bool $value): string
{
    return $value ? 'yes' : 'no';
}

/** @param bool|string $value */
function capabilityCell($value): string
{
    return is_string($value) ? capabilityStatusLabel($value) : capabilityYesNo((bool) $value);
}

/**
 * Curated builtin overrides (issue #1947, #1976): AOT session persistence + two-request execute.
 *
 * @return array<string, array{aot?: string, notes?: list<string>}>
 */
function builtinCapabilityCurations(): array
{
    $persistenceNote = 'AOT CGI file persistence (#1938); two-process model; opt-in SESSIONS_WEB_AOT_SMOKE_GATE / SESSIONS_WEB_DEPLOY_SMOKE_GATE';

    return [
        'session_start' => [
            'aot' => 'yes',
            'notes' => [$persistenceNote],
        ],
        'session_destroy' => [
            'aot' => 'yes',
            'notes' => [$persistenceNote],
        ],
        'session_regenerate_id' => [
            'aot' => 'yes',
            'notes' => [$persistenceNote],
        ],
        'session_write_close' => [
            'aot' => 'yes',
            'notes' => [$persistenceNote],
        ],
        'session_abort' => [
            'aot' => 'yes',
            'notes' => [$persistenceNote, 'lifecycle API (#6002)'],
        ],
        'session_reset' => [
            'aot' => 'yes',
            'notes' => [$persistenceNote, 'lifecycle API (#6002)', 'JIT PHPT'],
        ],
        'session_create_id' => [
            'aot' => 'yes',
            'notes' => [$persistenceNote, 'lifecycle API (#6002)', 'JIT PHPT'],
        ],
        'session_gc' => [
            'aot' => 'yes',
            'notes' => [$persistenceNote, 'lifecycle API (#6006)', 'JIT PHPT'],
        ],
        'move_uploaded_file' => [
            'aot' => 'yes',
            'notes' => [
                'nested $_FILES + multipart web runtime (#52, #87); 006-FileUploadWeb; FILE_UPLOAD_WEB_AOT_SMOKE_GATE (#2012); FILE_UPLOAD_WEB_DEPLOY_SMOKE_GATE (#2028)',
            ],
        ],
    ];
}

/**
 * @param array<string, array{vm: bool, jit: bool, aot: bool|string, notes: list<string>, module: string}> $capabilities
 *
 * @return array<string, array{vm: bool, jit: bool, aot: bool|string, notes: list<string>, module: string}>
 */
function applyBuiltinCapabilityCurations(array $capabilities): array
{
    foreach (builtinCapabilityCurations() as $name => $patch) {
        if (!isset($capabilities[$name])) {
            continue;
        }
        if (isset($patch['aot'])) {
            $capabilities[$name]['aot'] = $patch['aot'];
        }
        if (isset($patch['notes'])) {
            foreach ($patch['notes'] as $note) {
                if (!in_array($note, $capabilities[$name]['notes'], true)) {
                    $capabilities[$name]['notes'][] = $note;
                }
            }
        }
    }

    return $capabilities;
}

/**
 * Web north-star constructs for examples/003-MiniWebApp (issue #655).
 *
 * Status values are curated from closed/open ROADMAP issues, not compile probes.
 * VM/JIT/AOT cells use yes|no|partial|blocked|n/a.
 *
 * @return list<array{
 *   construct: string,
 *   vm: string,
 *   jit: string,
 *   aot: string,
 *   issue: int,
 *   notes: list<string>
 * }>
 */
function webNorthStarDefinitions(): array
{
    return [
        [
            'construct' => 'PATH_INFO / `?route=` fallback',
            'vm' => 'yes',
            'jit' => 'partial',
            'aot' => 'partial',
            'issue' => 489,
            'notes' => ['#489 VM closed; AOT execute ✅ (#764 closed); default-off gates #1760'],
        ],
        [
            'construct' => '`phpc_deploy_path()` + `PHPC_DEPLOY_ROOT`',
            'vm' => 'yes',
            'jit' => 'partial',
            'aot' => 'partial',
            'issue' => 585,
            'notes' => ['#585 closed; deploy includes #623; execute ✅ (#764 closed); gates #1760'],
        ],
        [
            'construct' => 'Runtime template `include` from deploy tree',
            'vm' => 'yes',
            'jit' => 'no',
            'aot' => 'partial',
            'issue' => 623,
            'notes' => ['#623 VM/AOT lint; execute ✅ (#764 closed); gates #1760'],
        ],
        [
            'construct' => 'CGI/1.1 driver (`bin/cgi.php`)',
            'vm' => 'yes',
            'jit' => 'n/a',
            'aot' => 'n/a',
            'issue' => 50,
            'notes' => ['#50 VM closed; #656 CgiDriverTest; #666 MiniWebApp PATH_INFO'],
        ],
        [
            'construct' => 'AOT CGI (`cgi-wrapper` + `phpc cgi`)',
            'vm' => 'n/a',
            'jit' => 'n/a',
            'aot' => 'partial',
            'issue' => 665,
            'notes' => ['#665 closed; 001 green; 003 execute ✅ (#764 closed); #682; gates #1760'],
        ],
        [
            'construct' => 'FastCGI loop',
            'vm' => 'partial',
            'jit' => 'n/a',
            'aot' => 'partial',
            'issue' => 173,
            'notes' => ['bin/fcgi.php + phpc fcgi; FASTCGI_SMOKE_GATE=1 (#173, #1899)'],
        ],
    ];
}

/** @param 'yes'|'no'|'partial'|'blocked' $status */
function capabilityStatusLabel(string $status): string
{
    return $status;
}

/**
 * @param list<array{construct: string, vm: string, jit: string, aot: string, issue: int, notes: list<string>}> $web
 */
function renderWebNorthStarMarkdown(array $web): string
{
    $lines = [
        '## Web north-star (`examples/003-MiniWebApp`)',
        '',
        'PATH_INFO routing, deploy-root includes, and CGI drivers for the reference web app.',
        'ROADMAP Phase 3/4: [#78](' . CAPABILITY_ISSUE_URL_BASE . '78), runtime tracker [#539]('
        . CAPABILITY_ISSUE_URL_BASE . '539). Builtin matrix: [capabilities.md](capabilities.md).',
        '',
        '| Construct | VM | JIT | AOT | Issue | Notes |',
        '|-----------|:--:|:---:|:---:|-------|-------|',
    ];

    foreach ($web as $row) {
        $lines[] = sprintf(
            '| %s | %s | %s | %s | [#%d](%s%d) | %s |',
            $row['construct'],
            capabilityStatusLabel($row['vm']),
            capabilityStatusLabel($row['jit']),
            capabilityStatusLabel($row['aot']),
            $row['issue'],
            CAPABILITY_ISSUE_URL_BASE,
            $row['issue'],
            $row['notes'] === [] ? '' : implode('; ', $row['notes'])
        );
    }

    $lines[] = '';
    $lines[] = '_Web rows are curated from ROADMAP issue state; native link [#568](' . CAPABILITY_ISSUE_URL_BASE
        . '568) closed; AOT execute [#764](' . CAPABILITY_ISSUE_URL_BASE . '764) closed; default-off CI gates [#1760]('
        . CAPABILITY_ISSUE_URL_BASE . '1760)._';
    $lines[] = '';

    return implode("\n", $lines);
}

/**
 * OOP constructs blocking MiniWebApp VM parity (issue #2190, #2059).
 *
 * @return list<array{
 *   construct: string,
 *   vm: string,
 *   jit: string,
 *   aot: string,
 *   issue: int,
 *   notes: list<string>
 * }>
 */
function miniWebAppOopNorthStarDefinitions(): array
{
    return [
        [
            'construct' => '`003-MiniWebApp` Router OOP (VM serve)',
            'vm' => 'yes',
            'jit' => 'partial',
            'aot' => 'yes',
            'issue' => 2059,
            'notes' => [
                '#2059 VM OOP e2e; lint zero (#2078); serve smoke default-on; JIT project opt-in (#587)',
            ],
        ],
        [
            'construct' => 'Public instance methods (`Expr_MethodCall`)',
            'vm' => 'yes',
            'jit' => 'partial',
            'aot' => 'yes',
            'issue' => 58,
            'notes' => ['#58 ClassMethod + method dispatch; compliance PHPT; native execute ✅ (#764 closed)'],
        ],
        [
            'construct' => 'Static methods (`Expr_StaticCall`)',
            'vm' => 'yes',
            'jit' => 'partial',
            'aot' => 'yes',
            'issue' => 2209,
            'notes' => ['#2209 user static methods; factory `Class::method()` + instance chain'],
        ],
        [
            'construct' => 'Private methods + `__construct`',
            'vm' => 'yes',
            'jit' => 'partial',
            'aot' => 'yes',
            'issue' => 145,
            'notes' => ['#145 visibility + ctor; Router private render paths'],
        ],
        [
            'construct' => 'Method return types (`: string` / `: void`)',
            'vm' => 'yes',
            'jit' => 'yes',
            'aot' => 'yes',
            'issue' => 55,
            'notes' => ['#55 native scalar/array returns; nullable via __value__*; MCJIT execute #2055'],
        ],
    ];
}

/**
 * @param list<array{construct: string, vm: string, jit: string, aot: string, issue: int, notes: list<string>}> $rows
 */
function renderMiniWebAppOopNorthStarMarkdown(array $rows): string
{
    $lines = [
        '## OOP reference (`examples/003-MiniWebApp`)',
        '',
        'Class methods, visibility, constructors, and return types for the MiniWebApp Router.',
        'ROADMAP Phase 1/4: [#78](' . CAPABILITY_ISSUE_URL_BASE . '78), acceptance [#2059]('
        . CAPABILITY_ISSUE_URL_BASE . '2059). Builtin matrix: [capabilities.md](capabilities.md).',
        '',
        '| Construct | VM | JIT | AOT | Issue | Notes |',
        '|-----------|:--:|:---:|:---:|-------|-------|',
    ];

    foreach ($rows as $row) {
        $lines[] = sprintf(
            '| %s | %s | %s | %s | [#%d](%s%d) | %s |',
            $row['construct'],
            capabilityStatusLabel($row['vm']),
            capabilityStatusLabel($row['jit']),
            capabilityStatusLabel($row['aot']),
            $row['issue'],
            CAPABILITY_ISSUE_URL_BASE,
            $row['issue'],
            $row['notes'] === [] ? '' : implode('; ', $row['notes'])
        );
    }

    $lines[] = '';
    $lines[] = '_OOP rows are curated from ROADMAP issue state; methods [#58](' . CAPABILITY_ISSUE_URL_BASE
        . '58); visibility/ctor [#145](' . CAPABILITY_ISSUE_URL_BASE . '145); return types [#55]('
        . CAPABILITY_ISSUE_URL_BASE . '55). Gates: `MINIWEBAPP_VM_OOP_GATE` default-on (#2293), '
        . '`MINIWEBAPP_JIT_PROJECT_GATE` (#587); drift guard `CAPABILITIES_OOP_SYNC_GATE` (#2190)._';
    $lines[] = '';

    return implode("\n", $lines);
}

/**
 * Session reference app constructs for examples/005-SessionsWeb (issue #1947).
 *
 * @return list<array{
 *   construct: string,
 *   vm: string,
 *   jit: string,
 *   aot: string,
 *   issue: int,
 *   notes: list<string>
 * }>
 */
function sessionsWebNorthStarDefinitions(): array
{
    return [
        [
            'construct' => '`005-SessionsWeb` reference app',
            'vm' => 'yes',
            'jit' => 'yes',
            'aot' => 'yes',
            'issue' => 1881,
            'notes' => [
                '#1881 VM serve + session smoke (#1887); AOT link #1946; execute #1891; ci-local gates opt-in (#1923, #1967)',
            ],
        ],
        [
            'construct' => '`session_start` / `$_SESSION` flash across requests',
            'vm' => 'yes',
            'jit' => 'yes',
            'aot' => 'yes',
            'issue' => 1938,
            'notes' => ['#1882 JIT; AOT persistence #1938; two-request execute #1891'],
        ],
        [
            'construct' => 'AOT project link (`phpc build --project`)',
            'vm' => 'n/a',
            'jit' => 'n/a',
            'aot' => 'yes',
            'issue' => 1946,
            'notes' => ['ExamplesCompileTest link-before-execute (#1946); SESSIONS_WEB_AOT_LINK_GATE opt-in'],
        ],
        [
            'construct' => 'AOT CLI execute (two-request session flash)',
            'vm' => 'n/a',
            'jit' => 'n/a',
            'aot' => 'yes',
            'issue' => 1891,
            'notes' => ['SessionsWebAotExecuteTest; opt-in SESSIONS_WEB_AOT_SMOKE_GATE (#1923)'],
        ],
    ];
}

/**
 * @param list<array{construct: string, vm: string, jit: string, aot: string, issue: int, notes: list<string>}> $rows
 */
function renderSessionsWebNorthStarMarkdown(array $rows): string
{
    $lines = [
        '## Sessions reference (`examples/005-SessionsWeb`)',
        '',
        'File-backed `$_SESSION` flash across HTTP requests for the sessions north-star example.',
        'ROADMAP Phase 4/5: [#78](' . CAPABILITY_ISSUE_URL_BASE . '78), tracker [#1881]('
        . CAPABILITY_ISSUE_URL_BASE . '1881). Builtin matrix: [capabilities.md](capabilities.md).',
        '',
        '| Construct | VM | JIT | AOT | Issue | Notes |',
        '|-----------|:--:|:---:|:---:|-------|-------|',
    ];

    foreach ($rows as $row) {
        $lines[] = sprintf(
            '| %s | %s | %s | %s | [#%d](%s%d) | %s |',
            $row['construct'],
            capabilityStatusLabel($row['vm']),
            capabilityStatusLabel($row['jit']),
            capabilityStatusLabel($row['aot']),
            $row['issue'],
            CAPABILITY_ISSUE_URL_BASE,
            $row['issue'],
            $row['notes'] === [] ? '' : implode('; ', $row['notes'])
        );
    }

    $lines[] = '';
    $lines[] = '_Sessions rows are curated from ROADMAP issue state; AOT persistence [#1938](' . CAPABILITY_ISSUE_URL_BASE
        . '1938); link [#1946](' . CAPABILITY_ISSUE_URL_BASE . '1946); execute [#1891](' . CAPABILITY_ISSUE_URL_BASE
        . '1891). Opt-in ci-local gates: `SESSIONS_WEB_AOT_SMOKE_GATE`, `SESSIONS_WEB_DEPLOY_SMOKE_GATE`._';
    $lines[] = '';

    return implode("\n", $lines);
}

/**
 * File upload reference app constructs for examples/006-FileUploadWeb (issue #2019).
 *
 * @return list<array{
 *   construct: string,
 *   vm: string,
 *   jit: string,
 *   aot: string,
 *   issue: int,
 *   notes: list<string>
 * }>
 */
function fileUploadWebNorthStarDefinitions(): array
{
    return [
        [
            'construct' => '`006-FileUploadWeb` reference app',
            'vm' => 'yes',
            'jit' => 'yes',
            'aot' => 'yes',
            'issue' => 1999,
            'notes' => [
                '#1999 VM serve + multipart smoke (#2009); AOT link #2011; execute #2012 (FILE_UPLOAD_WEB_AOT_SMOKE_GATE default-on)',
            ],
        ],
        [
            'construct' => 'multipart `$_POST` / nested `$_FILES` (web runtime)',
            'vm' => 'yes',
            'jit' => 'yes',
            'aot' => 'yes',
            'issue' => 87,
            'notes' => ['#52 multipart POST; #87 nested keys; AOT CGI REQUEST_BODY (#2012)'],
        ],
        [
            'construct' => 'AOT project link (`phpc build --project`)',
            'vm' => 'n/a',
            'jit' => 'n/a',
            'aot' => 'yes',
            'issue' => 2011,
            'notes' => ['ExamplesCompileTest::test006FileUploadWebAotLink; FILE_UPLOAD_WEB_AOT_LINK_GATE default-on'],
        ],
        [
            'construct' => 'AOT CGI execute (multipart upload probe)',
            'vm' => 'n/a',
            'jit' => 'n/a',
            'aot' => 'yes',
            'issue' => 2012,
            'notes' => ['FileUploadWebAotExecuteTest; FILE_UPLOAD_WEB_AOT_SMOKE_GATE default-on (#2012)'],
        ],
        [
            'construct' => 'AOT deploy CGI (multipart upload smoke)',
            'vm' => 'n/a',
            'jit' => 'n/a',
            'aot' => 'yes',
            'issue' => 2028,
            'notes' => ['deploy-smoke.sh --example 006; opt-in FILE_UPLOAD_WEB_DEPLOY_SMOKE_GATE (#2028, #2038)'],
        ],
    ];
}

/**
 * @param list<array{construct: string, vm: string, jit: string, aot: string, issue: int, notes: list<string>}> $rows
 */
function renderFileUploadWebNorthStarMarkdown(array $rows): string
{
    $lines = [
        '## File upload reference (`examples/006-FileUploadWeb`)',
        '',
        'Nested `$_FILES` and `move_uploaded_file()` for the multipart upload north-star example.',
        'ROADMAP Phase 4/5: [#78](' . CAPABILITY_ISSUE_URL_BASE . '78), tracker [#1999]('
        . CAPABILITY_ISSUE_URL_BASE . '1999). Builtin matrix: [capabilities.md](capabilities.md).',
        '',
        '| Construct | VM | JIT | AOT | Issue | Notes |',
        '|-----------|:--:|:---:|:---:|-------|-------|',
    ];

    foreach ($rows as $row) {
        $lines[] = sprintf(
            '| %s | %s | %s | %s | [#%d](%s%d) | %s |',
            $row['construct'],
            capabilityStatusLabel($row['vm']),
            capabilityStatusLabel($row['jit']),
            capabilityStatusLabel($row['aot']),
            $row['issue'],
            CAPABILITY_ISSUE_URL_BASE,
            $row['issue'],
            $row['notes'] === [] ? '' : implode('; ', $row['notes'])
        );
    }

    $lines[] = '';
    $lines[] = '_File upload rows are curated from ROADMAP issue state; multipart [#52](' . CAPABILITY_ISSUE_URL_BASE
        . '52); nested FILES [#87](' . CAPABILITY_ISSUE_URL_BASE . '87); `move_uploaded_file` [#2005]('
        . CAPABILITY_ISSUE_URL_BASE . '2005); deploy [#2028](' . CAPABILITY_ISSUE_URL_BASE . '2028). '
        . 'Opt-in ci-local gates: `FILE_UPLOAD_WEB_SMOKE_GATE`, `FILE_UPLOAD_WEB_AOT_LINK_GATE`, '
        . '`FILE_UPLOAD_WEB_AOT_SMOKE_GATE` (execute default-on #2012), `FILE_UPLOAD_WEB_DEPLOY_SMOKE_GATE`._';
    $lines[] = '';

    return implode("\n", $lines);
}

/**
 * Throws / try-catch reference app constructs for examples/007-ThrowsWeb (issues #2103, #2144).
 *
 * @return list<array{
 *   construct: string,
 *   vm: string,
 *   jit: string,
 *   aot: string,
 *   issue: int,
 *   notes: list<string>
 * }>
 */
function throwsWebNorthStarDefinitions(): array
{
    return [
        [
            'construct' => '`007-ThrowsWeb` reference app',
            'vm' => 'yes',
            'jit' => 'yes',
            'aot' => 'yes',
            'issue' => 2076,
            'notes' => [
                '#2076 VM serve + caught invalid POST (THROWS_WEB_SMOKE_GATE default #2125); AOT link/execute default #2135',
            ],
        ],
        [
            'construct' => '`throw` / `catch` on invalid POST (web serve)',
            'vm' => 'yes',
            'jit' => 'yes',
            'aot' => 'yes',
            'issue' => 195,
            'notes' => ['#195 throw lowering; #57 catch; #2084 compliance PHPT pack; empty user class JIT #2167; AOT #2157'],
        ],
        [
            'construct' => 'AOT project link (`phpc build --project`)',
            'vm' => 'n/a',
            'jit' => 'n/a',
            'aot' => 'yes',
            'issue' => 2101,
            'notes' => ['ExamplesCompileTest 007 link; THROWSWEB_AOT_LINK_GATE default-on (#2135)'],
        ],
        [
            'construct' => 'AOT CGI execute (caught throw probe)',
            'vm' => 'n/a',
            'jit' => 'n/a',
            'aot' => 'yes',
            'issue' => 2104,
            'notes' => ['examples-aot-smoke 007 slice; THROWSWEB_AOT_SMOKE_GATE default-on (#2135)'],
        ],
    ];
}

/**
 * @param list<array{construct: string, vm: string, jit: string, aot: string, issue: int, notes: list<string>}> $rows
 */
function renderThrowsWebNorthStarMarkdown(array $rows): string
{
    $lines = [
        '## Throws reference (`examples/007-ThrowsWeb`)',
        '',
        'Caught `throw` / `catch` on invalid POST for the throws north-star example.',
        'ROADMAP Phase 4/5: [#78](' . CAPABILITY_ISSUE_URL_BASE . '78), tracker [#2076]('
        . CAPABILITY_ISSUE_URL_BASE . '2076). Builtin matrix: [capabilities.md](capabilities.md).',
        '',
        '| Construct | VM | JIT | AOT | Issue | Notes |',
        '|-----------|:--:|:---:|:---:|-------|-------|',
    ];

    foreach ($rows as $row) {
        $lines[] = sprintf(
            '| %s | %s | %s | %s | [#%d](%s%d) | %s |',
            $row['construct'],
            capabilityStatusLabel($row['vm']),
            capabilityStatusLabel($row['jit']),
            capabilityStatusLabel($row['aot']),
            $row['issue'],
            CAPABILITY_ISSUE_URL_BASE,
            $row['issue'],
            $row['notes'] === [] ? '' : implode('; ', $row['notes'])
        );
    }

    $lines[] = '';
    $lines[] = '_Throws rows are curated from ROADMAP issue state; `throw` [#195](' . CAPABILITY_ISSUE_URL_BASE
        . '195); `try`/`catch` [#57](' . CAPABILITY_ISSUE_URL_BASE . '57); overlay [#2084](' . CAPABILITY_ISSUE_URL_BASE
        . '2084). ci-local gates: `THROWS_WEB_SMOKE_GATE` (VM serve default-on #2125), '
        . '`THROWSWEB_AOT_LINK_GATE` / `THROWSWEB_AOT_SMOKE_GATE` (AOT default-on #2135; set `0` to skip)._';
    $lines[] = '';

    return implode("\n", $lines);
}

/**
 * Stdlib array sort/merge builtins (issue #2298).
 *
 * Curated from closed stdlib issues; full matrix in capabilities.md.
 *
 * @return list<array{
 *   construct: string,
 *   vm: string,
 *   jit: string,
 *   aot: string,
 *   issue: int,
 *   notes: list<string>
 * }>
 */
function stdlibArrayBuiltinNorthStarDefinitions(): array
{
    return [
        [
            'construct' => '`ksort()` (string/int keys, preserve values)',
            'vm' => 'yes',
            'jit' => 'yes',
            'aot' => 'yes',
            'issue' => 2271,
            'notes' => ['String-key hashtable + packed list no-op; assoc subset (#66)'],
        ],
        [
            'construct' => '`krsort()` (keys descending)',
            'vm' => 'yes',
            'jit' => 'yes',
            'aot' => 'yes',
            'issue' => 2282,
            'notes' => ['String-key hashtable; packed list no-op'],
        ],
        [
            'construct' => '`asort()` (values ascending, preserve keys)',
            'vm' => 'yes',
            'jit' => 'yes',
            'aot' => 'yes',
            'issue' => 2290,
            'notes' => ['Homogeneous string/int values; packed + string-key assoc'],
        ],
        [
            'construct' => '`arsort()` (values descending, preserve keys)',
            'vm' => 'yes',
            'jit' => 'yes',
            'aot' => 'yes',
            'issue' => 2296,
            'notes' => ['Homogeneous string/int values; packed + string-key assoc'],
        ],
        [
            'construct' => '`rsort()` (values descending, reindex)',
            'vm' => 'yes',
            'jit' => 'yes',
            'aot' => 'yes',
            'issue' => 2300,
            'notes' => ['Packed homogeneous string/int lists; `__hashtable__sortPackedReverse`'],
        ],
        [
            'construct' => '`shuffle()` (packed list, Fisher–Yates)',
            'vm' => 'yes',
            'jit' => 'yes',
            'aot' => 'yes',
            'issue' => 2310,
            'notes' => ['Packed lists without holes; CSPRNG via random_bytes lowering'],
        ],
        [
            'construct' => '`array_rand()` (packed list keys)',
            'vm' => 'yes',
            'jit' => 'yes',
            'aot' => 'yes',
            'issue' => 2321,
            'notes' => ['Packed lists; num=1 JIT/AOT; num>1 VM-only; CSPRNG via random_bytes'],
        ],
        [
            'construct' => '`array_merge()` on string-key associative arrays',
            'vm' => 'yes',
            'jit' => 'yes',
            'aot' => 'yes',
            'issue' => 2287,
            'notes' => ['String-key maps; packed list append unchanged'],
        ],
    ];
}

/**
 * @param list<array{construct: string, vm: string, jit: string, aot: string, issue: int, notes: list<string>}> $rows
 */
function renderStdlibArrayBuiltinNorthStarMarkdown(array $rows): string
{
    $lines = [
        '## Stdlib array builtins (sort / merge)',
        '',
        'Recent stdlib coverage for associative arrays and packed lists.',
        'ROADMAP Phase 2/4: [#78](' . CAPABILITY_ISSUE_URL_BASE . '78), assoc arrays [#66]('
        . CAPABILITY_ISSUE_URL_BASE . '66). Full builtin matrix: [capabilities.md](capabilities.md).',
        '',
        '| Builtin | VM | JIT | AOT | Issue | Notes |',
        '|---------|:--:|:---:|:---:|-------|-------|',
    ];

    foreach ($rows as $row) {
        $lines[] = sprintf(
            '| %s | %s | %s | %s | [#%d](%s%d) | %s |',
            $row['construct'],
            capabilityStatusLabel($row['vm']),
            capabilityStatusLabel($row['jit']),
            capabilityStatusLabel($row['aot']),
            $row['issue'],
            CAPABILITY_ISSUE_URL_BASE,
            $row['issue'],
            $row['notes'] === [] ? '' : implode('; ', $row['notes'])
        );
    }

    $lines[] = '';
    $lines[] = '_Rows curated from closed stdlib issues (#2271, #2282, #2290, #2296, #2300, #2287); regenerate via `php script/capability-syntax.php`._';
    $lines[] = '';

    return implode("\n", $lines);
}
