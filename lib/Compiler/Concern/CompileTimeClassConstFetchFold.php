<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler\Concern;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Operand\Temporary;
use PHPCompiler\Block;
use PHPCompiler\BuiltinFunctionClassConstant;
use PHPCompiler\BuiltinTypeClassConstant;
use PHPCompiler\ClassConstName;
use PHPCompiler\VM\AttributeSupport;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;

/**
 * Compile-time ClassConstFetch folding (named / ::class / enum cases) (#36387).
 *
 * Extracted from {@see CompileTimeFold} so gen-0 split-TU can hollow a smaller Concern TU.
 * Call sites and visibility stay identical so LintCompiler overrides are unaffected.
 * Mirrors php-src Zend/zend_compile.c class-const / ::class folding — move-only.
 */
trait CompileTimeClassConstFetchFold
{
    protected function tryFoldClassConstFetchDefault(
        Op\Expr\ClassConstFetch $expr,
        Block $block,
        bool $materializeEnumCase = false
    ): ?Variable {
        $constName = $this->staticNameFromOperand($expr->name);
        if (null !== $constName && 'class' === strtolower($constName)) {
            $enumFqcn = $this->tryFoldEnumCaseClassPseudoConstFqcn($expr->class, $block);
            if (null !== $enumFqcn) {
                $value = new Variable(Variable::TYPE_STRING);
                $value->string($enumFqcn);

                return $value;
            }
            $builtinClass = $this->staticNameFromOperand($expr->class);
            if (null !== $builtinClass) {
                $builtinName = BuiltinTypeClassConstant::classNameForTypeOperand($builtinClass);
                if (null !== $builtinName) {
                    $value = new Variable(Variable::TYPE_STRING);
                    $value->string($builtinName);

                    return $value;
                }
                $builtinFn = BuiltinFunctionClassConstant::functionNameForClassOperand($builtinClass);
                if (null !== $builtinFn) {
                    $value = new Variable(Variable::TYPE_STRING);
                    $value->string($builtinFn);

                    return $value;
                }
                // self/parent/Named::class — compile-time string (zend_compile.c, #26629 / #3803).
                // static::class stays unfolded so LSB call sites keep the runtime opcode (#19614).
                $pseudoFqcn = $this->resolveCompileTimeClassPseudoConstFqcn($builtinClass, $block);
                if (null !== $pseudoFqcn) {
                    $value = new Variable(Variable::TYPE_STRING);
                    $value->string($pseudoFqcn);

                    return $value;
                }
            }
        }
        $className = $this->staticNameFromOperand($expr->class);
        if (null === $constName || null === $className) {
            return null;
        }
        // static::CONST / static::class need the called class at runtime (LSB).
        // Folding via the declaring class is self::-equivalent and wrong (#19614, zend_execute.c).
        if ('static' === strtolower($className)) {
            return null;
        }
        $lcClass = $this->resolveDefaultClassConstScope($className, $block);
        if (null === $lcClass) {
            return null;
        }
        if ($this->classCompileRegistry->isTrait($lcClass)) {
            return null;
        }
        $constKey = ClassConstName::key($constName);
        if (isset($this->compileTimeClassConsts[$lcClass][$constKey])) {
            // Class constants are case-sensitive — do not fold wrong casing (#25910, #25929).
            $declared = $this->compileTimeClassConstNames[$lcClass][$constKey] ?? null;
            if (!ClassConstName::matchesDeclared($constName, $declared)) {
                return null;
            }
            if (!$this->compileTimeClassConstFetchAllowed($lcClass, $constKey, $block)) {
                return null;
            }
            // Deprecated constants must fetch at runtime so E_USER_DEPRECATED fires (#6962).
            if (isset($this->compileTimeClassConstDeprecated[$lcClass][$constKey])) {
                return null;
            }
            $stored = $this->compileTimeClassConsts[$lcClass][$constKey];
            // Enum case fetches defer to runtime unless folding defaults/const-expr (#8767, #7399).
            if ($this->isCompileTimeEnumCaseConstantMember($lcClass, $constKey) && !$materializeEnumCase) {
                return null;
            }
            if ($this->compileTimeStoredValueIsEnumCaseBackingScalar($lcClass, $constKey, $stored)) {
                return $this->compileTimeEnumCaseVar(
                    $className,
                    $constName,
                    $stored,
                    $this->compileTimeEnumBackedTypes[$lcClass] ?? null
                );
            }
            // Non-literal duplicate backing falls back to runtime ensureBackedEnumValuesUnique (#5773).
            if (Variable::TYPE_OBJECT === $stored->type && EnumCaseSupport::isEnumCase($stored->toObject())) {
                if (!$materializeEnumCase) {
                    return null;
                }
                $value = new Variable();
                $value->copyFrom($stored);

                return $value;
            }
            if ($materializeEnumCase && Variable::TYPE_ENUM_CASE === $stored->type) {
                $value = new Variable();
                $value->copyFrom($stored);

                return $value;
            }
            if ($this->isCompileTimeEnumCaseConstantMember($lcClass, $constKey)) {
                return $this->materializeCompileTimeEnumCaseConstant(
                    $className,
                    $constName,
                    $stored,
                    $this->compileTimeEnumBackedTypes[$lcClass] ?? null
                );
            }
            $value = new Variable();
            $value->copyFrom($stored);

            return $value;
        }
        if (isset($this->runtimeEnumCaseConsts[$lcClass][$constKey])) {
            $stored = $this->runtimeEnumCaseConsts[$lcClass][$constKey];
            if ($this->isCompileTimeEnumCaseConstantMember($lcClass, $constKey) && !$materializeEnumCase) {
                return null;
            }
            if (Variable::TYPE_OBJECT === $stored->type && EnumCaseSupport::isEnumCase($stored->toObject())) {
                if (!$materializeEnumCase) {
                    return null;
                }
                $value = new Variable();
                $value->copyFrom($stored);

                return $value;
            }
            if ($materializeEnumCase && Variable::TYPE_ENUM_CASE === $stored->type) {
                $value = new Variable();
                $value->copyFrom($stored);

                return $value;
            }
            if ($this->isCompileTimeEnumCaseConstantMember($lcClass, $constKey)) {
                return $this->materializeCompileTimeEnumCaseConstant(
                    $className,
                    $constName,
                    $stored,
                    $this->compileTimeEnumBackedTypes[$lcClass] ?? null
                );
            }
            $value = new Variable();
            $value->copyFrom($stored);

            return $value;
        }

        return $this->tryFoldExternalClassConstFetch($className, $constName);
    }

    private function isCompileTimeEnumCaseConstantMember(string $lcClass, string $lcConst): bool
    {
        if (isset($this->compileTimeEnumCaseConstNames[$lcClass][$lcConst])) {
            return true;
        }
        if (!isset($this->runtimeEnumCaseConsts[$lcClass][$lcConst])) {
            return false;
        }
        $stored = $this->runtimeEnumCaseConsts[$lcClass][$lcConst];

        return Variable::TYPE_ENUM_CASE === $stored->type
            || (Variable::TYPE_OBJECT === $stored->type && EnumCaseSupport::isEnumCase($stored->toObject()));
    }

    /**
     * Fold enum `case` fetches to enum case objects — never expose backing scalars (#5933, #5858).
     */
    private function materializeCompileTimeEnumCaseConstant(
        string $enumName,
        string $caseName,
        Variable $stored,
        ?string $backedType
    ): Variable {
        if (Variable::TYPE_OBJECT === $stored->type && EnumCaseSupport::isEnumCase($stored->toObject())) {
            $value = new Variable();
            $value->copyFrom($stored);

            return $value;
        }
        if (Variable::TYPE_ENUM_CASE === $stored->type) {
            $value = new Variable();
            $value->copyFrom($stored);

            return $value;
        }
        $backing = new Variable();
        $backing->copyFrom($stored);

        return $this->compileTimeEnumCaseVar($enumName, $caseName, $backing, $backedType);
    }

    /**
     * Fold {@code EnumCase::class} to the enum type FQCN (Zend zend_compile.c; #5662).
     */
    protected function tryFoldEnumCaseClassPseudoConstFqcn(Operand $classOperand, Block $block): ?string
    {
        if ($classOperand instanceof Op\Expr\ClassConstFetch) {
            $inner = $this->tryFoldClassConstFetchDefault($classOperand, $block, true);
            if (null !== $inner) {
                $fqcn = $this->enumFqcnFromEnumCaseVariable($inner);
                if (null !== $fqcn) {
                    return $fqcn;
                }
            }
            $className = $this->staticNameFromOperand($classOperand->class);
            $caseName = $this->staticNameFromOperand($classOperand->name);
            if (null !== $className && null !== $caseName) {
                $lcClass = $this->resolveDefaultClassConstScope($className, $block);
                if (null !== $lcClass
                    && $this->isCompileTimeEnumCaseConstantMember($lcClass, ClassConstName::key($caseName))
                ) {
                    return ltrim($className, '\\');
                }
            }

            return null;
        }
        if (!$classOperand instanceof Operand\Variable && !$classOperand instanceof Temporary) {
            return null;
        }
        if (null === $block->orig) {
            return null;
        }
        foreach ($block->orig->children as $child) {
            if (!$child instanceof Op\Expr\ClassConstFetch
                || !$this->operandsReferToSameVariable($child->result, $classOperand)
            ) {
                continue;
            }
            $className = $this->staticNameFromOperand($child->class);
            $caseName = $this->staticNameFromOperand($child->name);
            if (null === $className || null === $caseName) {
                continue;
            }
            $lcClass = $this->resolveDefaultClassConstScope($className, $block);
            $lcConst = ClassConstName::key($caseName);
            if (null === $lcClass || !$this->isCompileTimeEnumCaseConstantMember($lcClass, $lcConst)) {
                continue;
            }
            $stored = $this->compileTimeClassConsts[$lcClass][$lcConst]
                ?? $this->runtimeEnumCaseConsts[$lcClass][$lcConst]
                ?? null;
            if (null !== $stored) {
                $fqcn = $this->enumFqcnFromEnumCaseVariable($stored);
                if (null !== $fqcn) {
                    return $fqcn;
                }
            }

            return ltrim($className, '\\');
        }

        return null;
    }

    protected function enumFqcnFromEnumCaseVariable(Variable $var): ?string
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_ENUM_CASE === $var->type) {
            return $var->toEnumCase()->enumClass->name;
        }
        if (Variable::TYPE_OBJECT === $var->type && EnumCaseSupport::isEnumCase($var->toObject())) {
            return $var->toObject()->class->name;
        }

        return null;
    }

    protected function tryFoldExternalClassConstFetch(string $className, string $constName): ?Variable
    {
        $lcClass = strtolower(ltrim($className, '\\'));
        // Attribute::* from compiler profile — never host \Attribute (#20727).
        // Leave Attribute::class to the ::class / native paths (not a TARGET_* bit).
        if ('attribute' === $lcClass && 'class' !== strtolower($constName)) {
            $folded = AttributeSupport::builtinConstValue(strtolower($constName));
            if (null === $folded) {
                return null;
            }
            $value = new Variable(Variable::TYPE_INTEGER);
            $value->int($folded);

            return $value;
        }
        if ('phpcfg\\func' === $lcClass) {
            $flags = [
                'FLAG_PUBLIC' => \PHPCfg\Func::FLAG_PUBLIC,
                'FLAG_PROTECTED' => \PHPCfg\Func::FLAG_PROTECTED,
                'FLAG_PRIVATE' => \PHPCfg\Func::FLAG_PRIVATE,
                'FLAG_STATIC' => \PHPCfg\Func::FLAG_STATIC,
                'FLAG_ABSTRACT' => \PHPCfg\Func::FLAG_ABSTRACT,
                'FLAG_FINAL' => \PHPCfg\Func::FLAG_FINAL,
                'FLAG_RETURNS_REF' => \PHPCfg\Func::FLAG_RETURNS_REF,
                'FLAG_CLOSURE' => \PHPCfg\Func::FLAG_CLOSURE,
            ];
            $lcConst = strtoupper($constName);
            if (!isset($flags[$lcConst])) {
                return null;
            }
            $value = new Variable(Variable::TYPE_INTEGER);
            $value->int($flags[$lcConst]);

            return $value;
        }

        return $this->tryFoldNativePhpClassConstFetch(ltrim($className, '\\'), $constName);
    }

    /**
     * Fold class constants from already-loaded native PHP classes (bootstrap spine; #6221).
     */
    protected function tryFoldNativePhpClassConstFetch(string $className, string $constName): ?Variable
    {
        // ::class on bootstrap Internal handlers may not be loaded yet (#1492 spine compile).
        $autoload = 'class' === strtolower($constName);
        if (!class_exists($className, $autoload)) {
            return null;
        }
        // Host PHP may still expose the constant while our PROFILE marks it #[\Deprecated]
        // (e.g. DATE path DateTime::RFC7231 under PROFILE=8.5 on a Zend 8.2 host). Refuse
        // fold so VM/JIT emit E_USER_DEPRECATED at fetch (#28134).
        if ($this->vmClassConstFetchIsDeprecated($className, $constName)) {
            return null;
        }
        try {
            $ref = new \ReflectionClassConstant($className, $constName);
        } catch (\ReflectionException) {
            return null;
        }
        $raw = $ref->getValue();
        if (\is_int($raw)) {
            $value = new Variable(Variable::TYPE_INTEGER);
            $value->int($raw);

            return $value;
        }
        if (\is_bool($raw)) {
            $value = new Variable(Variable::TYPE_BOOLEAN);
            $value->bool($raw);

            return $value;
        }
        if (\is_float($raw)) {
            $value = new Variable(Variable::TYPE_FLOAT);
            $value->float($raw);

            return $value;
        }
        if (\is_string($raw)) {
            $value = new Variable(Variable::TYPE_STRING);
            $value->string($raw);

            return $value;
        }

        return null;
    }

    /** True when VM ClassEntry marks the constant #[\Deprecated] for the active profile. */
    private function vmClassConstFetchIsDeprecated(string $className, string $constName): bool
    {
        if (null === $this->vmContext) {
            return false;
        }
        $lc = strtolower(ltrim($className, '\\'));
        $entry = $this->vmContext->classes[$lc] ?? null;
        if (null === $entry) {
            return false;
        }
        $key = ClassConstName::key($constName);
        $meta = $entry->constDeprecated[$key] ?? null;
        if (null === $meta) {
            return false;
        }

        return $meta->emitsRuntimeNotice();
    }

}
