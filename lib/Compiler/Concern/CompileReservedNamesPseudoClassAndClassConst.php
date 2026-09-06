<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ClassConstName;
use PHPCompiler\ClassConstVisibility;
use PHPCompiler\DnfType;
use PHPCompiler\Frame;
use PHPCompiler\GenericArrayTypeSpec;
use PHPCompiler\MethodVisibility;
use PHPCompiler\PropertyVisibility;
use PHPCompiler\JIT;
use PHPCompiler\VM;
use PHPCompiler\Block;
use PHPCompiler\OpCode;
use PHPCompiler\Func;
use PHPCompiler\Printer;
use PHPCompiler\Runtime;
use PHPCompiler\CompileResult;

use SplObjectStorage;
use PHPCfg\Func as CfgFunc;
use PHPCfg\Op;
use PHPCfg\Block as CfgBlock;
use PHPCfg\ErrorSuppressBlock;
use PHPCfg\Operand;
use PHPCfg\Operand\BoundVariable;
use PHPCfg\Operand\Literal;
use PHPCfg\Operand\NullOperand;
use PHPCfg\Operand\Temporary;
use PHPCfg\Operand\Variable as CfgVariable;
use PHPCfg\Script;
use PHPTypes\Type;
use PHPCompiler\VM\AttributeSupport;
use PHPCompiler\VM\ClassConstExpr;
use PHPCompiler\VM\ClassConstMaterializer;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context as VMContext;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\EnumSupport;
use PHPCompiler\VM\DateTimeInterfaceSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ReferencableCheck;
use PHPCompiler\VM\TypeCheck;
use PHPCompiler\VM\Variable;
use PHPCompiler\VM\VariableFunctionCall;
use PHPCompiler\VM\ClassReadonly;
use PHPCompiler\VM\ClassFinal;
use PHPCompiler\VM\ClosureRichDisplayName;
use PHPCompiler\JIT\OperandName;
use PHPCompiler\Ast\AsymmetricVisibilityRewriter;
use PHPCompiler\Ast\FinalPromotedPropertyRewriter;
use PHPCompiler\Ast\LazyPropertyRewriter;
use PHPCompiler\Ast\GeneratorYieldSourceMarker;
use PHPCompiler\Cfg\OpSubBlockAccess;
use PHPCompiler\Compiler\AbstractMethodBodyCheck;
use PHPCompiler\Compiler\AbstractMethodVisibilityCheck;
use PHPCompiler\Compiler\AbstractPromotedPropertyCompileCheck;
use PHPCompiler\Compiler\InterfaceConstAmbiguityCheck;
use PHPCompiler\Compiler\InterfaceConstVisibilityCheck;
use PHPCompiler\Compiler\InterfaceMethodBodyCheck;
use PHPCompiler\Compiler\InterfaceMethodFinalCheck;
use PHPCompiler\Compiler\InterfaceMethodVisibilityCheck;
use PHPCompiler\Compiler\EnumAbstractMethodCompileCheck;
use PHPCompiler\Compiler\EnumBuiltinMethodRedeclareCheck;
use PHPCompiler\Compiler\ClassConstDuplicateCheck;
use PHPCompiler\Compiler\ClosureUseDuplicateCompileCheck;
use PHPCompiler\Compiler\EnumBackedCaseCheck;
use PHPCompiler\Compiler\EnumMagicMethodCheck;
use PHPCompiler\Compiler\EnumParentCompileCheck;
use PHPCompiler\Compiler\MagicMethodArityCheck;
use PHPCompiler\Compiler\MagicMethodParamTypeCheck;
use PHPCompiler\Compiler\MagicMethodReturnTypeCheck;
use PHPCompiler\Compiler\MagicMethodStaticCheck;
use PHPCompiler\Compiler\PseudoClassTypeHintCompileCheck;
use PHPCompiler\Compiler\DuplicateUnionMemberCompileCheck;
use PHPCompiler\Compiler\RedundantDnfArmCompileCheck;
use PHPCompiler\Compiler\RedundantDnfArmSubsetCompileCheck;
use PHPCompiler\Compiler\RedundantObjectClassUnionCompileCheck;
use PHPCompiler\Compiler\IntersectionTypeMemberCompileCheck;
use PHPCompiler\Compiler\FunctionStaticAnonymousClassCompileCheck;
use PHPCompiler\Compiler\NewWithoutParensCompileCheck;
use PHPCompiler\Compiler\NonAbstractMethodBodyCheck;
use PHPCompiler\Compiler\NonEnumBuiltinInterfaceCompileCheck;
use PHPCompiler\Compiler\ThrowInClassConstCompileCheck;
use PHPCompiler\Compiler\AsymmetricVisibilityCompileCheck;
use PHPCompiler\Compiler\CompileFatal;
use PHPCompiler\Compiler\AttributeClassRegistry;
use PHPCompiler\Compiler\AttributeConstantEvaluator;
use PHPCompiler\Compiler\AttributeEntry;
use PHPCompiler\Compiler\AttributeMetadata;
use PHPCompiler\Compiler\AttributeNames;
use PHPCompiler\Compiler\AttributeTargetValidator;
use PHPCompiler\Compiler\DeprecatedMetadata;
use PHPCompiler\Compiler\NoDiscardMetadata;
use PHPCompiler\Compiler\FinalClassConstCheck;
use PHPCompiler\Compiler\TraitClassConstConflictCheck;
use PHPCompiler\Compiler\FinalClassExtensionCheck;
use PHPCompiler\Compiler\ImplementsHierarchyCompileCheck;
use PHPCompiler\VM\ImplementsHierarchyRuntimeCheck;
use PHPCompiler\Compiler\FinalMethodOverrideCheck;
use PHPCompiler\Compiler\FinalPropertyOverrideCheck;
use PHPCompiler\Compiler\InterfaceImplementationCheck;
use PHPCompiler\Compiler\ParameterMetadata;
use PHPCompiler\VM\ReflectionTypeSupport;
use PHPCompiler\Compiler\GeneratorNeverReturnCompileCheck;
use PHPCompiler\Compiler\GeneratorStaticMethodCompileCheck;
use PHPCompiler\Compiler\ReadonlyClassCompileCheck;
use PHPCompiler\Compiler\SourceLocation;
use PHPCompiler\Compiler\TraitCollisionCheck;
use PHPCompiler\Compiler\ClassConstVisibilityInheritCheck;
use PHPCompiler\Compiler\PropertyVisibilityInheritCheck;
use PHPCompiler\Compiler\TypedClassConstInheritCheck;
use PHPCompiler\Compiler\TypedPropertyInheritCheck;
use PHPCompiler\Compiler\VariadicPromotedPropertyCompileCheck;
use PHPCompiler\Compiler\ClassCompileRegistry;
use PHPCompiler\Compiler\OverrideValidator;
use PHPCompiler\Web\ConstStringFolder;
use PHPCompiler\Web\IncludePathResolver;
use PHPCompiler\Web\Superglobals;

/**
 * Reserved names / pseudo-class scope / compile-time class-const helpers (#36387 / #36403).
 *
 * Extracted from {@see ClassLikeAndStmtCompile} so gen-0 split-TU can hollow a smaller
 * Concern TU. Mirrors php-src Zend/zend_compile.c reserved const/class names,
 * zend_ensure_valid_class_fetch_type(), and compile-time class-const visibility —
 * move-only, no new C ABI.
 *
 * Visibility stays protected/public so LintCompiler and call sites are unchanged.
 */
trait CompileReservedNamesPseudoClassAndClassConst
{
    /**
     * php-src Zend/zend_compile.c {@code reserved_class_names} / {@code zend_is_reserved_class_name()} (#32206).
     * Match is case-insensitive on the unqualified name; {@code parent}/{@code self}/{@code static}
     * and {@code array}/{@code callable} are in the C table (usually parse errors as identifiers).
     *
     * @var array<string, true>
     */
    private const RESERVED_CLASS_NAMES = [
        'bool' => true,
        'false' => true,
        'float' => true,
        'int' => true,
        'null' => true,
        'parent' => true,
        'self' => true,
        'static' => true,
        'string' => true,
        'true' => true,
        'void' => true,
        'never' => true,
        'iterable' => true,
        'object' => true,
        'mixed' => true,
        'array' => true,
        'callable' => true,
    ];

    /**
     * php-src zend_compile_const_decl() + zend_get_special_const() (#32228).
     * File-scope `const true` / `false` / `null` (any case, any namespace prefix) is a
     * compile fatal. Message preserves the source spelling of the unqualified name.
     * `define('true', 1)` stays a runtime warning — do not call this from the define() path.
     */
    protected function rejectReservedGlobalConstName(Op\Terminal\Const_ $const): void
    {
        $name = $this->staticNameFromOperand($const->name);
        if (null === $name || '' === $name) {
            return;
        }
        $unqualified = $name;
        if (str_contains($name, '\\')) {
            $parts = explode('\\', $name);
            $unqualified = $parts[count($parts) - 1];
        }
        $lc = strtolower($unqualified);
        if ('true' !== $lc && 'false' !== $lc && 'null' !== $lc) {
            return;
        }
        $detail = sprintf("Cannot redeclare constant '%s'", $unqualified);
        $sourceFile = $const->getFile();
        if ('' === $sourceFile) {
            $sourceFile = 'unknown';
        }
        $this->throwCompileError($detail, $sourceFile, $const->getLine());
    }

    /**
     * php-src zend_compile_class_const_declaration() + zend_check_const_and_trait_alias_name() (#32251).
     * Declared class/interface/trait/enum constant named `class` (any case) is a compile fatal;
     * `Foo::class` the pseudo-constant is a fetch, not a declaration.
     */
    protected function rejectReservedClassConstName(?string $constName, Op\Terminal\Const_ $const): void
    {
        if (null === $constName || '' === $constName) {
            return;
        }
        $unqualified = $constName;
        if (str_contains($constName, '\\')) {
            $parts = explode('\\', $constName);
            $unqualified = $parts[count($parts) - 1];
        }
        if ('class' !== strtolower($unqualified)) {
            return;
        }
        $detail = "A class constant must not be called 'class'; it is reserved for class name fetching";
        $sourceFile = $const->getFile();
        if ('' === $sourceFile) {
            $sourceFile = 'unknown';
        }
        $this->throwCompileError($detail, $sourceFile, $const->getLine());
    }

    /**
     * php-src zend_assert_valid_class_name() — compile fatal before TYPE_DECLARE_* (#32206).
     * Message shape is PHP 8.2/8.3: Cannot use '%s' as class name as it is reserved.
     */
    protected function assertNotReservedClassName(string $name, Op $op): void
    {
        $unqualified = $name;
        if (str_contains($name, '\\')) {
            $parts = explode('\\', $name);
            $unqualified = $parts[count($parts) - 1];
        }
        if (!isset(self::RESERVED_CLASS_NAMES[strtolower($unqualified)])) {
            return;
        }
        $detail = sprintf("Cannot use '%s' as class name as it is reserved", $unqualified);
        $sourceFile = $op->getFile();
        if ('' === $sourceFile) {
            $sourceFile = 'unknown';
        }
        $this->throwCompileError($detail, $sourceFile, $op->getLine());
    }


    /**
     * @return list<string>
     */
    public function pushIncludeTargetCompile(): void
    {
        ++$this->includeTargetCompileDepth;
    }

    public function popIncludeTargetCompile(): void
    {
        if ($this->includeTargetCompileDepth > 0) {
            --$this->includeTargetCompileDepth;
        }
    }

    protected function pseudoClassInCompileScope(string $className, Block $block): bool
    {
        $lc = strtolower($className);
        if (!in_array($lc, ['self', 'parent', 'static'], true)) {
            return true;
        }
        if (null !== $this->compilingClassLc) {
            return true;
        }
        if (null !== $this->evalClassScopeLc) {
            return true;
        }
        if ($this->includeTargetCompileDepth > 0 && $block->isMainScript()) {
            return true;
        }

        return null !== $block->func && null !== $block->func->class;
    }

    /**
     * Zend zend_is_scope_known() when !CG(active_class_entry) (#32227, Zend/zend_compile.c).
     *
     * Scope is known-empty in a named free function. File/eval ({main}) inherit the
     * including/eval'ing class; closures can be rebound — both stay runtime.
     */
    protected function compileScopeKnowsNoClassEntry(Block $block): bool
    {
        $func = $block->func;
        if (null === $func) {
            return false;
        }
        if (((int) ($func->flags ?? 0) & \PHPCfg\Func::FLAG_CLOSURE) !== 0) {
            return false;
        }
        $name = $func->name;
        if ($name instanceof Operand\Literal) {
            $name = $name->value;
        }
        if (!is_string($name) || '' === $name || '{main}' === $name) {
            return false;
        }
        if (ClosureRichDisplayName::isClosureCfgName($name)) {
            return false;
        }

        return null === $func->class;
    }

    /**
     * Zend zend_ensure_valid_class_fetch_type() — self/parent/static::method in a free function
     * is a compile-time fatal even when the function is never called (#32227).
     */
    protected function rejectPseudoClassStaticCallOutsideClassScope(Op\Expr\StaticCall $expr, Block $block): void
    {
        $keyword = $this->firstClassCallableScopeKeyword($expr->class);
        $this->rejectPseudoClassFetchOutsideKnownClassScope($keyword, $block, $expr);
    }

    /**
     * Zend zend_compile_new() → zend_ensure_valid_class_fetch_type() — `new self/parent/static`
     * in a named free function is a compile-time fatal even when unused (#32252, re-#32227).
     */
    protected function rejectPseudoClassNewOutsideClassScope(Op\Expr\New_ $expr, Block $block): void
    {
        $keyword = $this->firstClassCallableScopeKeyword($expr->class);
        $this->rejectPseudoClassFetchOutsideKnownClassScope($keyword, $block, $expr);
    }

    /**
     * @param null|'parent'|'self'|'static' $keyword
     */
    protected function rejectPseudoClassFetchOutsideKnownClassScope(?string $keyword, Block $block, Op $source): void
    {
        if (null === $keyword) {
            return;
        }
        if ($this->pseudoClassInCompileScope($keyword, $block)) {
            return;
        }
        if (!$this->compileScopeKnowsNoClassEntry($block)) {
            return;
        }
        $sourceFile = $source->getFile();
        if ('' === $sourceFile) {
            $sourceFile = 'unknown';
        }
        $this->throwCompileError(
            PseudoClassTypeHintCompileCheck::messageFor($keyword),
            $sourceFile,
            $source->getLine()
        );
    }

    /**
     * Zend zend_compile.c — self/parent/static return & parameter types require class scope (#17480).
     *
     * @return never
     */
    protected function rejectPseudoClassTypeHintOutsideClassScope(?Op\Type $type, Block $block, CfgFunc $func): void
    {
        $keyword = PseudoClassTypeHintCompileCheck::findKeyword($type);
        if (null === $keyword) {
            return;
        }
        if ($this->pseudoClassInCompileScope($keyword, $block)) {
            return;
        }
        $callable = $func->callableOp;
        throw new CompileFatal(
            $callable instanceof Op ? ($callable->getFile() ?: 'unknown') : 'unknown',
            $callable instanceof Op ? max(1, $callable->getLine()) : 1,
            PseudoClassTypeHintCompileCheck::messageFor($keyword)
        );
    }

    protected function resolveDefaultClassConstScope(string $className, Block $block): ?string
    {
        $lc = strtolower($className);
        if ('self' === $lc || 'static' === $lc) {
            if (null !== $this->compilingClassLc) {
                return $this->compilingClassLc;
            }
            if (null !== $block->func && null !== $block->func->class) {
                $name = $this->staticNameFromOperand($block->func->class);

                return null !== $name ? strtolower(ltrim($name, '\\')) : null;
            }
            // eval() donor is declaring class — `static::` must not fold here (#31912, #19614).
            if ('self' === $lc && null !== $this->evalClassScopeLc) {
                return $this->evalClassScopeLc;
            }

            return null;
        }
        if ('parent' === $lc) {
            return null;
        }

        return strtolower(ltrim($className, '\\'));
    }

    /**
     * Fold {@code self::class} / {@code parent::class} / {@code Named::class} to the FQCN string
     * (Zend zend_compile.c class name resolution; #26629, #3803).
     *
     * {@code static::class} returns null — late-static binding needs the runtime opcode (#19614).
     * Trait {@code self::class} / {@code parent::class} also return null — Zend retargets them to
     * the composing class at use-time (#26659, #19629, Zend/zend_traits.c); folding the trait
     * name would bake the wrong string into method bodies (regression from #26629).
     */
    protected function resolveCompileTimeClassPseudoConstFqcn(string $className, Block $block): ?string
    {
        $lc = strtolower(ltrim($className, '\\'));
        if ('static' === $lc) {
            return null;
        }
        if ('self' === $lc) {
            $declLc = $this->declaringClassLcForTypeHint($block);
            if (null !== $declLc && $this->classCompileRegistry->isTrait($declLc)) {
                return null;
            }
            $display = $this->declaringClassDisplayNameForTypeHint($block);

            return null !== $display && '' !== $display ? $display : null;
        }
        if ('parent' === $lc) {
            $declLc = $this->declaringClassLcForTypeHint($block);
            if (null !== $declLc && $this->classCompileRegistry->isTrait($declLc)) {
                return null;
            }
            if (null !== $this->compilingClassLc) {
                $parent = $this->compilingClassParentDisplayName();
                if (null === $parent || '' === $parent) {
                    throw new CompileFatal(
                        'unknown',
                        1,
                        EnumParentCompileCheck::MESSAGE
                    );
                }

                return $parent;
            }

            return null;
        }

        return ltrim($className, '\\');
    }

    /**
     * Zend message when {@code static::} appears in a property default (#26629, #31145).
     *
     * {@code static::class} keeps the more specific zend_compile.c diagnostic; other
     * {@code static::CONST} fetches use {@see ThrowInClassConstCompileCheck::STATIC_SCOPE_MESSAGE}.
     */
    protected function propertyDefaultStaticClassRejectMessage(Op\Stmt\Property $prop): ?string
    {
        $fetch = $this->findStaticScopeClassConstFetchInBlock($prop->defaultBlock);
        if (null === $fetch) {
            $root = null !== $prop->defaultVar ? $this->unwrapOperandChain($prop->defaultVar) : null;
            if ($root instanceof Op\Expr\ClassConstFetch) {
                $fetch = $root;
            }
        }
        if (null === $fetch) {
            return null;
        }

        return ThrowInClassConstCompileCheck::staticScopeRejectMessage($fetch);
    }

    /**
     * Reject {@code static::} in class-const / param-default / property-default const-exprs (#31145).
     */
    protected function rejectStaticScopeInCompileTimeConstExpr(
        ?CfgBlock $block,
        Op $site,
        ?Operand $value = null
    ): void {
        $fetch = $this->findStaticScopeClassConstFetchInBlock($block);
        if (null === $fetch && null !== $value) {
            $root = $this->unwrapOperandChain($value);
            if ($root instanceof Op\Expr\ClassConstFetch) {
                $fetch = $root;
            }
        }
        if (null === $fetch) {
            return;
        }
        $msg = ThrowInClassConstCompileCheck::staticScopeRejectMessage($fetch);
        if (null === $msg) {
            return;
        }
        $sourceFile = $fetch->getFile();
        if ('' === $sourceFile) {
            $sourceFile = $site->getFile();
        }
        if ('' === $sourceFile) {
            $sourceFile = 'unknown';
        }
        $line = $fetch->getLine();
        if ($line < 1) {
            $line = $site->getLine();
        }
        throw new CompileFatal($sourceFile, max(1, $line), $msg);
    }

    protected function findStaticScopeClassConstFetchInBlock(?CfgBlock $block): ?Op\Expr\ClassConstFetch
    {
        if (null === $block) {
            return null;
        }
        $queue = [$block];
        $seen = new SplObjectStorage();
        while ([] !== $queue) {
            $current = array_shift($queue);
            if ($seen->contains($current)) {
                continue;
            }
            $seen->attach($current);
            foreach ($current->children ?? [] as $op) {
                if (!$op instanceof Op) {
                    continue;
                }
                if ($op instanceof Op\Expr\ClassConstFetch
                    && null !== ThrowInClassConstCompileCheck::staticScopeRejectMessage($op)
                ) {
                    return $op;
                }
                if ($op instanceof Op\Expr\Assign
                    && $op->expr instanceof Op\Expr\ClassConstFetch
                    && null !== ThrowInClassConstCompileCheck::staticScopeRejectMessage($op->expr)
                ) {
                    return $op->expr;
                }
                OpSubBlockAccess::enqueueSubBlocks($op, $queue);
            }
        }

        return null;
    }

    protected function compileTimeClassConstFetchCallerLc(Block $block): ?string
    {
        if (null !== $this->compilingClassLc) {
            return $this->compilingClassLc;
        }
        if (null !== $block->func && null !== $block->func->class) {
            $name = $this->staticNameFromOperand($block->func->class);

            return null !== $name ? strtolower(ltrim($name, '\\')) : null;
        }

        return null;
    }

    /**
     * Whether a compile-time class const value may be constant-folded at this site (#6784).
     */
    protected function compileTimeClassConstFetchAllowed(
        string $declaringClassLc,
        string $constLc,
        Block $block
    ): bool {
        $vis = $this->compileTimeClassConstVisibility[$declaringClassLc][$constLc] ?? CfgFunc::FLAG_PUBLIC;
        if (MethodVisibility::isPublic($vis)) {
            return true;
        }
        try {
            ClassConstVisibility::assertAccessible(
                $vis,
                $this->compileTimeClassConstFetchCallerLc($block),
                $declaringClassLc,
                $this->classCompileRegistry->traitDisplayName($declaringClassLc),
                $constLc,
                fn (string $callerLc, string $ancestorLc): bool => $this->classCompileRegistry->isClassSubtypeOf(
                    $callerLc,
                    $ancestorLc
                )
            );
        } catch (\LogicException) {
            return false;
        }

        return true;
    }

}
