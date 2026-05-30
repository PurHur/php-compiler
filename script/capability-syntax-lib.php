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
            'notes' => ['php-cfg inline Stmt\\Class_ in parseExpr_New; synthetic AnonymousClass@line name'],
            'probe' => '$o = new class { public function f(): int { return 42; } }; echo $o->f();',
        ],
        [
            'id' => 'enum_declarations',
            'construct' => 'Enum declarations `enum Foo: string { case Bar = \'x\'; }`',
            'opcodes' => ['TYPE_DECLARE_ENUM', 'TYPE_DECLARE_CLASS_CONST', 'TYPE_CLASS_CONST_FETCH'],
            'issue' => 1356,
            'notes' => ['Backed enum cases as class constants; `Foo::Bar` const-like fetch; `enum_exists` registry; `implements` metadata (#2299); static methods (#2299)'],
            'probe' => 'enum Status: string { case Ok = \'ok\'; public static function tag(): string { return \'ok\'; } } echo Status::tag();',
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
            'id' => 'match_expr',
            'construct' => '`match` expression',
            'opcodes' => ['TYPE_IDENTICAL', 'TYPE_JUMPIF', 'TYPE_ASSIGN'],
            'issue' => 143,
            'notes' => [
                'Lowered in php-cfg to === / jump-if / assign (#143)',
                'Wave-3 literal-arm subset (#2398); acceptance PHPT (#2428)',
            ],
            'probe' => 'echo match (2) { 1 => "a", 2 => "b", default => "c" };',
        ],
        [
            'id' => 'closures',
            'construct' => 'Closures `function () { }` / `use ($var)`',
            'opcodes' => ['TYPE_CLOSURE'],
            'issue' => 72,
            'aot' => false,
            'notes' => [
                'VM ClosureState + __invoke; use() by-value and by-ref (#3081, #3108); array_map/filter/usort callbacks (#3086)',
                'JIT ClosureHelper: TYPE_CLOSURE + use() value/ref IR (#3092, #3108); indirect $arr[0]() via __closure_target (#3089, #3092)',
                'bootstrap / self-host AOT stubs null; bin/jit.php MCJIT execute still probe-dependent (#98)',
            ],
            'probe' => '$f = function ($x) { return $x + 1; }; echo $f(2);',
        ],
        [
            'id' => 'arrow_functions',
            'construct' => 'Arrow functions `fn () =>`',
            'opcodes' => ['TYPE_CLOSURE'],
            'issue' => 142,
            'aot' => false,
            'notes' => ['Desugars to TYPE_CLOSURE (#142); VM + JIT same as closures (#3092, #3108)'],
            'probe' => '$f = fn ($x) => $x + 1; echo $f(2);',
        ],
        [
            'id' => 'generator_yield',
            'construct' => 'Generators (`yield` / `foreach`)',
            'opcodes' => ['TYPE_YIELD', 'TYPE_ITER_RESET', 'TYPE_ITER_VALID', 'TYPE_ITER_VALUE'],
            'issue' => 167,
            'jit' => false,
            'aot' => false,
            'notes' => [
                'VM GeneratorState + foreach; keyed yield (#3085)',
                'bin/jit.php VM fallback via Block::requiresVmLowering (#3085); see docs/generators-jit-aot.md',
                '`yield from` generator path supported on VM (#167)',
            ],
            'probe' => 'function g() { yield 1; yield 2; } foreach (g() as $v) echo $v;',
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
            'notes' => ['MiniWebApp `Router::DEFAULT_CONTACT_NAME_MAX` (#2059)'],
            'probe' => 'class C { private const M = 1; public function f(): int { return self::M; } } echo (new C())->f();',
        ],
        [
            'id' => 'late_static_binding',
            'construct' => 'Late static binding `static::method()` / `static::class`',
            'opcodes' => ['TYPE_STATICCALL_INIT', 'TYPE_CLASS_CONST_FETCH'],
            'issue' => 1231,
            'notes' => ['VM/JIT called-class propagation; parent::method/class/$prop and static:: LSB (#1858, #3093)'],
            'probe' => 'class C { public static function id(): string { return static::class; } } echo C::id();',
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
            'notes' => ['Packed and string-keyed arrays; VM + JIT lowering'],
            'probe' => '$a = [1, 2, 3]; foreach ($a as &$v) { $v *= 2; } echo $a[0], $a[1], $a[2];',
        ],
        [
            'id' => 'ref_param',
            'construct' => 'By-reference parameters (`function f(&$x)`)',
            'opcodes' => ['TYPE_ARG_RECV', 'TYPE_ARG_SEND'],
            'issue' => 140,
            'jit' => false,
            'aot' => false,
            'notes' => ['VM aliases caller slots via TYPE_INDIRECT; JIT pointer args deferred'],
            'probe' => 'function inc(&$n) { $n++; } $x = 1; inc($x); echo $x;',
        ],
        [
            'id' => 'static_property_fetch',
            'construct' => 'Static property `Class::$prop`',
            'opcodes' => ['TYPE_STATIC_PROPERTY_FETCH', 'TYPE_DECLARE_STATIC_PROPERTY'],
            'issue' => 1225,
            'notes' => ['Class-scoped storage; `self::` / `static::`; literal property names in JIT'],
            'probe' => 'class C { public static int $n = 1; } echo C::$n;',
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
            'construct' => 'Function-local `static $var = <literal>`',
            'opcodes' => ['TYPE_DECLARE_FUNCTION_STATIC'],
            'issue' => 2286,
            'notes' => ['Literal int/string init only in v1; VM + JIT + AOT'],
            'probe' => 'function f(){static $n=0; $n++; return $n;} echo f().f().f();',
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
            'notes' => ['php-cfg lowers labels to CFG Jump; VM avoids frame nesting on same-block back-edges'],
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
                'php-cfg Expr_FirstClassCallable (#1230); VM stores string or [obj, method] array',
                'JIT folds strlen(...) / Class::m(...) via compileTimeString assign chains (#1363)',
                'php-types TypeReconstructor patch for Expr_FirstClassCallable (#2315)',
            ],
            'probe' => '$fn = strlen(...); echo $fn("x");',
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
            'opcodes' => ['TYPE_DECLARE_CLASS', 'TYPE_USE_TRAIT', 'TYPE_DECLARE_METHOD'],
            'issue' => 2314,
            'jit' => false,
            'aot' => false,
            'notes' => [
                'php-cfg-trait-use.patch; VM merges trait methods into class',
                'TraitUseAdaptation (alias/insteadof) is #144',
            ],
            'probe' => 'trait T { public function m(): int { return 1; } } class C { use T; } echo (new C())->m();',
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
                'JIT TryCatchHelper IR verify (#3107); bin/jit.php VM fallback via requiresVmLowering (#2114); MCJIT execute unsafe',
            ],
            'probe' => 'class E {} try { throw new E(); } catch (E $e) { echo "ok"; }',
        ],
        [
            'id' => 'readonly_class',
            'construct' => 'readonly classes',
            'opcodes' => ['TYPE_DECLARE_CLASS', 'TYPE_NEW', 'TYPE_ASSIGN', 'TYPE_PROPERTY_FETCH'],
            'issue' => 1360,
            'notes' => ['php-cfg Class_::flags MODIFIER_READONLY; VM rejects instance property writes after __construct'],
            'probe' => 'readonly class R { public int $x = 0; } $o = new R(); $o->x = 1;',
        ],
        [
            'id' => 'php8_attribute_reflection',
            'construct' => 'PHP 8 attributes — `ReflectionClass` / `ReflectionMethod` metadata',
            'opcodes' => ['TYPE_DECLARE_CLASS', 'TYPE_DECLARE_METHOD'],
            'issue' => 1936,
            'notes' => [
                'php-cfg preserves `attrGroups`; compiler stores attribute class names on TYPE_DECLARE_CLASS / TYPE_DECLARE_METHOD',
                'VM reflection reads from VM ClassEntry; JIT/AOT mirror class+method attribute tables into VMContext for reflection',
                'Read path: `getAttributes()` count + `ReflectionAttribute::getName()`; no `newInstance()` or parameter attributes',
            ],
            'probe' => '#[\AllowDynamicProperties] class B {} $a = (new ReflectionClass(B::class))->getAttributes(); echo count($a).$a[0]->getName();',
        ],
        [
            'id' => 'weak_reference_weak_map',
            'construct' => 'WeakReference / WeakMap',
            'opcodes' => [],
            'issue' => 3282,
            'jit' => false,
            'notes' => [
                'VM: WeakReference::create/get via indirect target slot; unset clears get immediately',
                'GC-backed weak refs via WeakRefRegistry — referent collected by gc_collect_cycles() clears get()',
                'WeakMap uses object-id string keys; entries removed when key object is collected',
                'JIT may compile references but method bodies are VM-only',
            ],
            'probe' => 'class Box {} $o = new Box(); $r = WeakReference::create($o); unset($o); echo $r->get() === null ? "1" : "0";',
        ],
        [
            'id' => 'datetime_oop',
            'construct' => 'DateTime / DateTimeZone OOP',
            'opcodes' => ['TYPE_NEW'],
            'issue' => 3072,
            'jit' => false,
            'aot' => false,
            'notes' => [
                'VM builtins: DateTime::__construct/format/getTimestamp/setTimezone; DateTimeZone::__construct',
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
        'private_methods' => '/\bprivate\s+function\b/',
        'method_return_types' => '/function\s+\w+\([^)]*\)\s*:\s*(?:string|void|int)/',
        'property_fetch' => '/\$this->\w+/',
        'dynamic_property_fetch' => '/->\$/',
        'native_user_class' => '/\b(?:class\s+\w+|new\s+\w+)/',
        'instanceof' => '/\binstanceof\b/',
        'match_expr' => '/\bmatch\s*\(/',
        'closures' => '/function\s*\([^)]*\)\s*(?:use\s*\([^)]*\)\s*)?\{/',
        'arrow_functions' => '/\bfn\s*\(/',
        'generator_yield' => '/\byield\b/',
        'class_name_const' => '/::class\b/',
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

    foreach ($scan as $path) {
        $content = (string) file_get_contents($path);
        $isPhpt = str_ends_with($path, '.phpt');
        $body = $isPhpt && preg_match('/--FILE--\s*\n<\?php(.*?)(?:--EXPECT|$)/s', $content, $m)
            ? $m[1]
            : $content;
        $tag = $isPhpt ? 'compliance PHPT' : 'bootstrap AOT';
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
            'vm' => 'no',
            'jit' => 'no',
            'aot' => 'no',
            'issue' => 173,
            'notes' => [],
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
        . CAPABILITY_ISSUE_URL_BASE . '55). Opt-in gates: `MINIWEBAPP_VM_OOP_GATE` (#2189), '
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
            'notes' => ['#195 throw lowering; #57 catch; #2084 compliance PHPT pack; user empty class AOT #2157'],
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
