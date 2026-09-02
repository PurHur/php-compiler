<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler\Concern;

use PHPCfg\Func as CfgFunc;
use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCompiler\Compiler\AttributeConstantEvaluator;
use PHPCompiler\Compiler\AttributeEntry;
use PHPCompiler\Compiler\AttributeMetadata;
use PHPCompiler\Compiler\AttributeNames;
use PHPCompiler\Compiler\CompileFatal;

/**
 * Parameter / binding name compile-time asserts (#36230 step 2).
 *
 * Extracted from {@see \PHPCompiler\Compiler} behind the opcode-corpus-md5 gate.
 * Visibility stays protected so LintCompiler and call sites are unchanged.
 */
trait ParameterAsserts
{
    /**
     * @param list<Op\Expr\Param> $params
     */
    protected function assertNoDuplicateParameterNames(array $params): void
    {
        $seen = [];
        foreach ($params as $param) {
            if (!($param->name instanceof Operand\Literal) || !is_string($param->name->value)) {
                continue;
            }
            $name = $param->name->value;
            if (isset($seen[$name])) {
                // Zend/zend_compile.c — file+line CompileFatal for CLI "Fatal error:" shape (#29979).
                $detail = sprintf('Redefinition of parameter $%s', $name);
                $sourceFile = $param->getFile();
                if ('' === $sourceFile) {
                    $sourceFile = 'unknown';
                }
                if (null === $this->compileAbortDetail) {
                    $this->compileAbortDetail = $detail;
                }
                throw new CompileFatal($sourceFile, max(1, $param->getLine()), $detail);
            }
            $seen[$name] = true;
        }
    }

    /**
     * php-src: Zend/zend_compile.c zend_compile_params() — $this is never a legal parameter name (#32179).
     *
     * @param list<Op\Expr\Param> $params
     */
    protected function assertNoThisAsParameter(array $params): void
    {
        foreach ($params as $param) {
            if (!($param->name instanceof Operand\Literal) || !is_string($param->name->value)) {
                continue;
            }
            if ('this' !== $param->name->value) {
                continue;
            }
            $detail = 'Cannot use $this as parameter';
            $sourceFile = $param->getFile();
            if ('' === $sourceFile) {
                $sourceFile = 'unknown';
            }
            $this->throwCompileError($detail, $sourceFile, $param->getLine());
        }
    }

    /**
     * @param list<Op\Expr\Param> $params
     */
    protected function assertVariadicParamIsLast(array $params): void
    {
        $seenVariadic = false;
        foreach ($params as $param) {
            if ($param->variadic) {
                $seenVariadic = true;
                continue;
            }
            if ($seenVariadic) {
                $this->throwCompileError('Only the last parameter can be variadic');
            }
        }
    }

    /**
     * @param list<Op\Expr\Param> $params
     */
    protected function assertNoDuplicateParameterAttributes(array $params, ?CfgFunc $func = null): void
    {
        AttributeConstantEvaluator::withUserlandConstContext(
            $this->userlandConstScalarsForAttributes(),
            $this->namespaceHintFromFunc($func),
            function () use ($params): void {
                foreach ($params as $param) {
                    $entries = AttributeMetadata::fromOp($param);
                    $names = AttributeEntry::namesFromList($entries);
                    AttributeNames::assertAllowDynamicPropertiesClassTargetOnly($names, 'parameter', $entries);
                    AttributeNames::assertAttributeMetaClassTargetOnly($names, 'parameter', $entries);
                    AttributeNames::assertOverrideMethodTargetOnly($names, 'parameter', $entries);
                    AttributeNames::assertCompileTimeConstTargetOnly($names, 'parameter', $entries);
                    AttributeNames::assertSensitiveParameterParamTargetOnly($names, 'parameter', $entries);
                    AttributeNames::assertReturnTypeWillChangeMethodTargetOnly($names, 'parameter', $entries);
                    AttributeNames::assertDeprecatedTargetAllowed($names, 'parameter', $entries);
                    AttributeNames::validateDuplicates($entries, $this->attributeClassRegistry);
                }
            }
        );
    }

    /**
     * php-src: Zend/zend_compile.c — readonly on parameters is only valid in __construct (#6291).
     *
     * @param list<Op\Expr\Param> $params
     */
    protected function assertReadonlyParamOnlyInConstructor(array $params, ?CfgFunc $func): void
    {
        if (null !== $func && '__construct' === $func->name && null !== $func->class) {
            return;
        }
        foreach ($params as $param) {
            if (!$this->isPromotedParamReadonly($param)) {
                continue;
            }
            $this->throwCompileError('Cannot declare promoted property outside a constructor');
        }
    }

    /**
     * php-src: Zend/zend_compile.c zend_compile_closure_binding() — $this is never a legal use() name (#32152).
     *
     * @param list<Operand\BoundVariable> $closureUseVars
     */
    protected function assertNoThisInClosureUseVars(array $closureUseVars, Op $source): void
    {
        foreach ($closureUseVars as $useVar) {
            if ('this' !== $this->boundVariableName($useVar)) {
                continue;
            }
            $detail = 'Cannot use $this as lexical variable';
            $sourceFile = $source->getFile();
            if ('' === $sourceFile) {
                $sourceFile = 'unknown';
            }
            $this->throwCompileError($detail, $sourceFile, $source->getLine());
        }
    }

    /**
     * php-src: Zend/zend_compile.c zend_compile_global_var() — $this is never a legal global name (#32180).
     */
    protected function assertNoThisAsGlobalVariable(string $globalName, Op $source): void
    {
        if ('this' !== $globalName) {
            return;
        }
        $detail = 'Cannot use $this as global variable';
        $sourceFile = $source->getFile();
        if ('' === $sourceFile) {
            $sourceFile = 'unknown';
        }
        $this->throwCompileError($detail, $sourceFile, $source->getLine());
    }

    /**
     * php-src: Zend/zend_compile.c zend_compile_static_var() — $this is never a legal function-static name (#32181).
     */
    protected function assertNoThisAsStaticVariable(string $varName, Op $source): void
    {
        if ('this' !== $varName) {
            return;
        }
        $detail = 'Cannot use $this as static variable';
        $sourceFile = $source->getFile();
        if ('' === $sourceFile) {
            $sourceFile = 'unknown';
        }
        $this->throwCompileError($detail, $sourceFile, $source->getLine());
    }

    /**
     * php-src: Zend/zend_compile.c zend_compile_catch() — catch variable named $this is a compile-time fatal (#32204).
     */
    protected function assertNoThisAsCatchVariable(?Operand $catchVar, Op $source): void
    {
        if (null === $catchVar) {
            return;
        }
        $name = $this->resolveCatchVariableName($catchVar);
        if (null === $name) {
            $name = $this->baseVariableName($catchVar);
        }
        if ('this' !== $name) {
            return;
        }
        $detail = 'Cannot re-assign $this';
        $sourceFile = $source->getFile();
        if ('' === $sourceFile) {
            $sourceFile = 'unknown';
        }
        $this->throwCompileError($detail, $sourceFile, $source->getLine());
    }
}
