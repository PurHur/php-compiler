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
 * Param typed-default matching + optional-before-required / implicit-nullable
 * compile-time deprecations (#36387).
 *
 * Extracted from {@see ClassLikeAndStmtCompile} / {@see \PHPCompiler\Compiler}
 * behind the opcode-corpus-md5 gate. Visibility stays protected/private so LintCompiler
 * and call sites are unchanged. Mirrors php-src Zend/zend_compile.c
 * {@code zend_compile_params} / typed default checks.
 */
trait CompileParamTypedDefaultAndDeprecation
{
    /**
     * Non-nullable declared type with `= null` default (php-src implicit nullable, #4449).
     */
    protected function paramIsImplicitNullable(Op\Expr\Param $param, ?int $defaultSlot, Block $block): bool
    {
        if (null === $defaultSlot || null === $param->declaredType) {
            return false;
        }
        if ($param->declaredType instanceof Op\Type\Nullable) {
            return false;
        }
        if ($this->cfgTypeUsesDnfShape($param->declaredType)) {
            return false;
        }
        $default = $block->constants[$defaultSlot] ?? null;

        return null !== $default && Variable::TYPE_NULL === $default->type;
    }

    /**
     * Zend compile-time E_DEPRECATED for optional-before-required params (#31904).
     *
     * php-src: Zend/zend_compile.c {@code zend_compile_params} — last required (non-default,
     * non-variadic) parameter names the message; PHP 5-style {@code Type $p = null} is skipped
     * ({@code forced_allow_nullable} / implicit nullable). PHP 8.4+ prefixes the callable label.
     *
     * @param list<Op\Expr\Param> $params
     */
    protected function maybeEmitOptionalBeforeRequiredParamDeprecations(array $params, Block $block): void
    {
        $lastRequired = -1;
        foreach ($params as $i => $param) {
            if ($param->variadic) {
                continue;
            }
            if (null === $param->defaultVar && null === $param->defaultBlock) {
                $lastRequired = $i;
            }
        }
        if ($lastRequired < 0) {
            return;
        }
        $requiredParam = $params[$lastRequired] ?? null;
        if (!$requiredParam instanceof Op\Expr\Param) {
            return;
        }
        $requiredName = $this->displayParamName($requiredParam);
        $callablePrefix = '';
        if (CompilerVersion::supportsOptionalBeforeRequiredCallablePrefix()) {
            $callablePrefix = $this->displayCallableNameForCompileDeprecation($block).'(): ';
        }
        foreach ($params as $i => $param) {
            if ($i >= $lastRequired || $param->variadic) {
                continue;
            }
            if (null === $param->defaultVar && null === $param->defaultBlock) {
                continue;
            }
            if ($this->paramSkipsOptionalBeforeRequiredDeprecation($param, $block, (int) $i)) {
                continue;
            }
            $this->emitCompileTimeInternalDeprecated(
                sprintf(
                    '%sOptional parameter %s declared before required parameter %s is implicitly treated as a required parameter',
                    $callablePrefix,
                    $this->displayParamName($param),
                    $requiredName
                ),
                $block,
                max(0, $param->getLine())
            );
        }
    }

    /**
     * PHP 5-style {@code Type $param = null} is not a true optional (zend_compile.c, #31904).
     */
    protected function paramSkipsOptionalBeforeRequiredDeprecation(Op\Expr\Param $param, Block $block, int $paramIdx): bool
    {
        $defaultSlot = null;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ARG_RECV === $op->type && (int) $op->arg2 === $paramIdx) {
                $defaultSlot = $op->arg3;
                break;
            }
        }
        if (null !== $defaultSlot) {
            return $this->paramIsImplicitNullable($param, $defaultSlot, $block);
        }
        if (null === $param->declaredType || $param->declaredType instanceof Op\Type\Nullable) {
            return false;
        }
        if ($this->cfgTypeUsesDnfShape($param->declaredType)) {
            return false;
        }

        return $this->paramAstDefaultIsNull($param);
    }

    /**
     * Zend E_DEPRECATED during CFG compile (eval: VM running; file-level: {@see $vmContext}).
     */
    protected function emitCompileTimeInternalDeprecated(string $message, Block $block, int $line): void
    {
        $vm = VM::running();
        $context = $vm instanceof VM ? $vm->context : $this->vmContext;
        if (!$context instanceof VMContext) {
            return;
        }

        $file = $block->scriptPath();
        if ('' === $file) {
            $file = $this->debugLastPhaseInputFile;
        }
        if (null === $file || '' === $file) {
            $file = null;
        }
        $frame = null;
        if ($vm instanceof VM) {
            $frame = $vm->builtinHandlerFrame();
            if (null === $frame) {
                $frames = $context->runStackFrames();
                $frame = [] !== $frames ? $frames[0] : null;
            }
        }
        if (null === $frame) {
            $frame = new Frame(null, $block, null);
            $frame->vmContext = $context;
            if (null !== $file) {
                $frame->scriptPath = $file;
            }
        }

        $context->errors->internalDeprecated(
            $message,
            $context,
            $frame,
            $file,
            $line
        );
    }

    /**
     * Zend 8.4 compile-time deprecation for implicit nullable typed parameters (#21390, #22987, #29274).
     *
     * Emits during CFG compile for both eval (VM running) and file-level parseAndCompile
     * (VM not running yet — use {@see $vmContext} from Runtime). Message is prefixed with the
     * Zend zend_error callable label ({@see displayCallableNameForCompileDeprecation()}).
     */
    protected function maybeEmitImplicitNullableParamDeprecation(
        Op\Expr\Param $param,
        ?int $defaultSlot,
        Block $block
    ): void {
        if (!CompilerVersion::supportsImplicitNullableParameterDeprecation()) {
            return;
        }
        if (!$this->paramIsImplicitNullable($param, $defaultSlot, $block)) {
            return;
        }

        $this->emitCompileTimeInternalDeprecated(
            sprintf(
                '%s(): Implicitly marking parameter %s as nullable is deprecated, the explicit nullable type must be used instead',
                $this->displayCallableNameForCompileDeprecation($block),
                $this->displayParamName($param)
            ),
            $block,
            max(0, $param->getLine())
        );
    }

    /**
     * Zend zend_error-style callable label for compile-time deprecations (#29274).
     *
     * Named function → {@code f}; method → {@code C::m}; closure/arrow on PROFILE≥8.4 →
     * {@code {closure:file:line}} (same shape as Closure::__debugInfo); else {@code {closure}}.
     */
    protected function displayCallableNameForCompileDeprecation(Block $block): string
    {
        $func = $block->func;
        if (null === $func || !\is_string($func->name) || '' === $func->name || '{main}' === $func->name) {
            return '{closure}';
        }
        $name = $func->name;
        if (str_starts_with($name, '{anonymous}') || str_starts_with($name, '{closure')) {
            if (CompilerVersion::supportsClosureRichDebugInfo()) {
                if (null !== $block->closureRichDisplayName && '' !== $block->closureRichDisplayName) {
                    return $block->closureRichDisplayName;
                }
                $callable = $func->callableOp ?? null;
                if ($callable instanceof Op) {
                    $file = $callable->getFile();
                    $line = max(0, (int) $callable->getLine());
                    if (\is_string($file) && '' !== $file && $line > 0) {
                        return '{closure:'.$file.':'.$line.'}';
                    }
                }
            }

            return '{closure}';
        }
        if (null !== $func->class) {
            $class = $this->staticNameFromOperand($func->class);
            if ((null === $class || '' === $class) && null !== $this->compilingClassDisplayName) {
                $class = $this->compilingClassDisplayName;
            }
            if (null !== $class && '' !== $class) {
                return ltrim($class, '\\').'::'.$name;
            }
        }

        return $name;
    }

    /**
     * PHP 8.4+ {@code {closure:…:line}} for anonymous/arrow (zend_compile.c, #30076).
     *
     * @param Op\Expr\ArrowFunction|Op\Expr\Closure $expr
     */
    private function computeClosureRichDisplayName(Block $enclosing, $expr): ?string
    {
        if (!CompilerVersion::supportsClosureRichDebugInfo()) {
            return null;
        }
        $line = max(0, (int) $expr->getLine());
        $file = $expr->getFile();
        if (!\is_string($file) || '' === $file) {
            $file = $enclosing->scriptPath();
        }
        $parentRich = null;
        $enclosingFunc = $enclosing->func;
        if (null !== $enclosingFunc && null !== $this->closureRichNameByFunc
            && isset($this->closureRichNameByFunc[$enclosingFunc])
        ) {
            $parentRich = (string) $this->closureRichNameByFunc[$enclosingFunc];
        } elseif (null !== $enclosing->closureRichDisplayName && '' !== $enclosing->closureRichDisplayName) {
            $parentRich = $enclosing->closureRichDisplayName;
        }

        return ClosureRichDisplayName::fromEnclosingBlock(
            $enclosing,
            $line,
            $parentRich,
            \is_string($file) ? $file : null,
            $this->compilingClassDisplayName
        );
    }

    /** Declaring class while compiling a method body (null at free function / top-level). */
    private function closureDeclaringClassFromEnclosing(Block $enclosing): ?string
    {
        $func = $enclosing->func;
        if (null !== $func && null !== $func->class) {
            $classVal = $func->class->value ?? null;
            if (\is_string($classVal) && '' !== $classVal) {
                return ltrim($classVal, '\\');
            }
        }
        if (null !== $enclosing->closureDeclaringClass && '' !== $enclosing->closureDeclaringClass) {
            // Nested in a method-scoped closure: keep the outer method's class (#30076).
            return $enclosing->closureDeclaringClass;
        }
        if (null !== $this->compilingClassDisplayName && '' !== $this->compilingClassDisplayName) {
            $name = null !== $func && \is_string($func->name) ? $func->name : '';
            if ('' !== $name && '{main}' !== $name && !ClosureRichDisplayName::isClosureCfgName($name)) {
                return ltrim($this->compilingClassDisplayName, '\\');
            }
        }

        return null;
    }

    /** Stamp PHP 8.4 rich name onto every block in a closure body (#30076). */
    private function propagateClosureRichDisplayName(
        Block $entry,
        ?string $richDisplayName,
        ?string $declaringClass = null
    ): void {
        $queue = [$entry];
        $seen = new SplObjectStorage();
        while ([] !== $queue) {
            $block = array_pop($queue);
            if ($seen->contains($block)) {
                continue;
            }
            $seen[$block] = true;
            if (null !== $richDisplayName) {
                $block->closureRichDisplayName = $richDisplayName;
            }
            if (null !== $declaringClass) {
                $block->closureDeclaringClass = $declaringClass;
            }
            foreach ($block->blocks as $child) {
                if ($child instanceof Block) {
                    $queue[] = $child;
                }
            }
            foreach ($block->opCodes as $op) {
                if (null !== $op->block1 && !$seen->contains($op->block1)) {
                    // Stay inside this closure — do not descend into nested TYPE_CLOSURE bodies.
                    if (OpCode::TYPE_CLOSURE === $op->type) {
                        continue;
                    }
                    $queue[] = $op->block1;
                }
                if (null !== $op->block2 && !$seen->contains($op->block2)) {
                    $queue[] = $op->block2;
                }
            }
        }
    }

    private function displayParamName(Op\Expr\Param $param): string
    {
        if ($param->name instanceof Operand\Literal && is_string($param->name->value) && '' !== $param->name->value) {
            return '$'.$param->name->value;
        }

        return '$?';
    }

    /**
     * Zend zend_compile.c: property/param defaults must match declared type (#5347, #6558).
     */
    protected function assertParamDefaultMatchesDeclaredType(Op\Expr\Param $param, ?int $defaultSlot, Block $block): void
    {
        if (null === $defaultSlot || null === $param->declaredType) {
            return;
        }
        $default = $block->constants[$defaultSlot] ?? null;
        if (null === $default) {
            return;
        }
        $paramName = '?';
        if ($param->name instanceof Operand\Literal && is_string($param->name->value)) {
            $paramName = '$'.$param->name->value;
        }
        $this->assertCompileTimeDefaultMatchesDeclaredType(
            $default,
            $param->declaredType,
            'parameter',
            $paramName,
            $block,
            $defaultSlot,
            $param
        );
    }

    /**
     * Zend zend_compile.c — zend_verify_const_expr_type() for property/param defaults (#6558).
     */
    protected function assertCompileTimeDefaultMatchesDeclaredType(
        Variable $default,
        ?Op\Type $declaredType,
        string $kind,
        string $targetName,
        ?Block $block = null,
        ?int $defaultSlot = null,
        ?Op\Expr\Param $param = null,
        ?string $sourceFile = null,
        ?int $sourceLine = null
    ): void {
        if (null === $declaredType) {
            return;
        }

        $value = $default->resolveIndirect();

        if ($declaredType instanceof Op\Type\Mixed_) {
            return;
        }
        if ($declaredType instanceof Op\Type\Literal && 'mixed' === strtolower($declaredType->name)) {
            return;
        }

        if (
            'parameter' === $kind
            && null !== $param
            && null !== $defaultSlot
            && null !== $block
            && $this->paramIsImplicitNullable($param, $defaultSlot, $block)
        ) {
            return;
        }

        $checkType = $declaredType;
        if ($declaredType instanceof Op\Type\Nullable) {
            if (Variable::TYPE_NULL === $value->type) {
                return;
            }
            $checkType = $declaredType->subtype;
        }

        if (
            $this->cfgTypeUsesDnfShape($checkType)
            || $checkType instanceof Op\Type\Union
            || $checkType instanceof Op\Type\Intersection
        ) {
            return;
        }

        $typeLabel = $this->declNameFromCfgType($checkType) ?? 'mixed';

        if ($checkType instanceof Op\Type\Literal) {
            $nameLc = strtolower($checkType->name);
            if ('true' === $nameLc || 'false' === $nameLc) {
                if (Variable::TYPE_BOOLEAN === $value->type && $value->toBool() === ('true' === $nameLc)) {
                    return;
                }
                $given = TypeCheck::typeNameForConstraint($value->type);
                $this->throwTypedDefaultMismatch($given, $kind, $targetName, $nameLc, $sourceFile, $sourceLine);

                return;
            }
        }

        if ($value->is(Variable::TYPE_ARRAY)) {
            if ($checkType instanceof Op\Type\Literal) {
                $nameLc = strtolower($checkType->name);
                if ('array' === $nameLc || 'iterable' === $nameLc) {
                    return;
                }
            }
            if (null !== $this->genericArraySpecFromCfgType($checkType)) {
                return;
            }
            $this->throwTypedDefaultMismatch('array', $kind, $targetName, $typeLabel, $sourceFile, $sourceLine);

            return;
        }

        if ($checkType instanceof Op\Type\Literal && $this->compileTimeDefaultMatchesLiteralType($value, strtolower($checkType->name))) {
            return;
        }

        $classOrScalarName = $this->declNameFromCfgType($checkType);
        if (null !== $classOrScalarName && null !== $block) {
            $resolvedClass = $this->resolveTypeHintClassName($classOrScalarName, $block);
            if (null !== $resolvedClass && '' !== $resolvedClass) {
                $classOrScalarName = $resolvedClass;
            }
        }
        if (
            null !== $classOrScalarName
            && $this->compileTimeDefaultMatchesLiteralType($value, strtolower($classOrScalarName))
        ) {
            return;
        }

        $given = TypeCheck::typeNameForConstraint($value->type);
        $this->throwTypedDefaultMismatch($given, $kind, $targetName, $typeLabel, $sourceFile, $sourceLine);
    }

    /**
     * Zend zend_compile.c — property null defaults use a dedicated fatal (#31820);
     * with file/line → CompileFatal / "PHP Fatal error:" CLI shape (follow-up to #31827).
     */
    protected function throwTypedDefaultMismatch(
        string $given,
        string $kind,
        string $targetName,
        string $typeLabel,
        ?string $sourceFile = null,
        ?int $sourceLine = null
    ): void {
        if ('property' === $kind && 'null' === $given) {
            $this->throwCompileError(
                "Default value for property of type {$typeLabel} may not be null. Use the nullable type ?{$typeLabel} to allow null default value",
                $sourceFile,
                $sourceLine
            );
        }

        $this->throwCompileError(
            "Cannot use {$given} as default value for {$kind} {$targetName} of type {$typeLabel}",
            $sourceFile,
            $sourceLine
        );
    }

    protected function compileTimeDefaultMatchesLiteralType(Variable $value, string $typeNameLc): bool
    {
        switch ($typeNameLc) {
            case 'int':
                return $value->is(Variable::TYPE_INTEGER);
            case 'float':
                return $value->is(Variable::TYPE_FLOAT) || $value->is(Variable::TYPE_INTEGER);
            case 'string':
                return $value->is(Variable::TYPE_STRING);
            case 'bool':
                return $value->is(Variable::TYPE_BOOLEAN);
            case 'array':
                return $value->is(Variable::TYPE_ARRAY);
            case 'iterable':
                return $value->is(Variable::TYPE_ARRAY);
            case 'null':
                return $value->is(Variable::TYPE_NULL);
            default:
                return $this->compileTimeDefaultMatchesClassType($value, $typeNameLc);
        }
    }

    protected function compileTimeDefaultMatchesClassType(Variable $value, string $expectedClassLc): bool
    {
        $value = $value->resolveIndirect();
        $expectedClassLc = strtolower(ltrim($expectedClassLc, '\\'));

        if (Variable::TYPE_ENUM_CASE === $value->type) {
            return strtolower(ltrim($value->toEnumCase()->enumClass->name, '\\')) === $expectedClassLc;
        }
        if (Variable::TYPE_OBJECT === $value->type) {
            $obj = $value->toObject();
            if (EnumCaseSupport::isEnumCase($obj)) {
                return strtolower(ltrim($obj->class->name, '\\')) === $expectedClassLc;
            }

            return strtolower(ltrim($obj->class->name, '\\')) === $expectedClassLc;
        }

        return false;
    }
}
