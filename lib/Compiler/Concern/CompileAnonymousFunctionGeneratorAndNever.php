<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Config;
use PHPCompiler\Block;
use PHPCompiler\OpCode;
use PHPCompiler\Compiler\AttributeNames;
use PHPCompiler\Compiler\ClosureUseDuplicateCompileCheck;
use PHPCompiler\Ast\GeneratorYieldSourceMarker;

use SplObjectStorage;
use PHPCfg\Func as CfgFunc;
use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Operand\BoundVariable;
use PHPCfg\Operand\Literal;

/**
 * Closure / arrow / generator marking and `: never` reachability (#36387).
 *
 * Extracted from {@see ErrorSuppressAndPropertyFetch} so gen-0 split-TU can hollow
 * a smaller Concern TU. Call sites and visibility stay identical so LintCompiler
 * overrides are unaffected. Mirrors php-src Zend/zend_compile.c closure binding /
 * generator / never paths — move-only, no new C ABI.
 */
trait CompileAnonymousFunctionGeneratorAndNever
{
    /**
     * @param Op\Expr\ArrowFunction|Op\Expr\Closure $expr
     *
     * @return OpCode[]
     */
    protected function compileAnonymousFunctionExpr($expr, Block $block): array
    {
        if ($this->shouldStubClosureForBootstrap()) {
            $resultSlot = $this->compileOperand($expr->result, $block, false);
            $nullSlot = $this->compileOperand(new Operand\Literal(null), $block, true);

            return [new OpCode(
                OpCode::TYPE_ASSIGN,
                $resultSlot,
                $resultSlot,
                $nullSlot
            )];
        }
        $func = $expr->func;
        $wasArrowAutoCapture = $this->compilingArrowAutoCapture;
        // Nested closure/arrow bodies must bind catch CVs through closureCaptures, not the
        // outer catch-handler slot map. Rewriting reads to the handler slot made use($e) /
        // fn() => $e appear to work only when that CV happened to be slot 0 (#25897).
        $savedCatchVarSlots = $this->activeCatchVarSlotsByName;
        $savedCatchVarRoots = $this->activeCatchVarRoots;
        $this->activeCatchVarSlotsByName = [];
        $this->activeCatchVarRoots = [];
        // PHP 8.4+: compute Zend rich name before body compile so nested closures nest (#30076).
        $richDisplayName = $this->computeClosureRichDisplayName($block, $expr);
        $declaringClass = $this->closureDeclaringClassFromEnclosing($block);
        if (null !== $richDisplayName) {
            if (null === $this->closureRichNameByFunc) {
                $this->closureRichNameByFunc = new SplObjectStorage();
            }
            $this->closureRichNameByFunc[$func] = $richDisplayName;
        }
        if ($expr instanceof Op\Expr\ArrowFunction) {
            $this->compilingArrowAutoCapture = true;
        }
        $closureUseVars = [];
        if ($expr instanceof Op\Expr\Closure) {
            foreach ($expr->useVars as $useVar) {
                if ($useVar instanceof Operand\BoundVariable) {
                    $closureUseVars[] = $useVar;
                }
            }
            // php-src zend_compile_closure_binding: $this, then lexical-table uniqueness (#32152, #32153).
            $this->assertNoThisInClosureUseVars($closureUseVars, $expr);
            $dupUseName = ClosureUseDuplicateCompileCheck::firstDuplicateName($closureUseVars);
            if (null !== $dupUseName) {
                $sourceFile = $expr->getFile();
                if ('' === $sourceFile) {
                    $sourceFile = 'unknown';
                }
                $this->throwCompileError(
                    ClosureUseDuplicateCompileCheck::messageFor($dupUseName),
                    $sourceFile,
                    $expr->getLine()
                );
            }
        }
        try {
            $funcBlock = $this->compileCfgBlock($func->cfg, $func->params, $func, $closureUseVars);
            $funcBlock->parents[] = $block;
        } finally {
            $this->compilingArrowAutoCapture = $wasArrowAutoCapture;
            $this->activeCatchVarSlotsByName = $savedCatchVarSlots;
            $this->activeCatchVarRoots = $savedCatchVarRoots;
        }
        if (null !== $richDisplayName) {
            $funcBlock->closureRichDisplayName = $richDisplayName;
            $this->propagateClosureRichDisplayName($funcBlock, $richDisplayName, $declaringClass);
        } elseif (null !== $declaringClass) {
            $funcBlock->closureDeclaringClass = $declaringClass;
            $this->propagateClosureRichDisplayName($funcBlock, null, $declaringClass);
        }
        $this->markGeneratorIfNeeded($expr, $funcBlock);
        $op = new OpCode(
            OpCode::TYPE_CLOSURE,
            $this->compileOperand($expr->result, $block, false),
        );
        $op->block1 = $funcBlock;
        if (null !== $richDisplayName) {
            $op->closureRichDisplayName = $richDisplayName;
        }
        if (null !== $declaringClass) {
            $op->closureDeclaringClass = $declaringClass;
        }
        $op->parameterMetadata = $this->parameterMetadataFromParams($func->params, $func);
        $this->assignAttributeMetadata($op, $expr);
        $this->assignSourceMetadata($op, $expr);
        AttributeNames::assertAllowDynamicPropertiesClassTargetOnly($op->attributeNames, 'function', $op->attributeEntries);
        AttributeNames::assertAttributeMetaClassTargetOnly($op->attributeNames, 'function', $op->attributeEntries);
        AttributeNames::assertCompileTimeConstTargetOnly($op->attributeNames, 'function', $op->attributeEntries);
        AttributeNames::assertSensitiveParameterParamTargetOnly($op->attributeNames, 'function', $op->attributeEntries);
        AttributeNames::assertReturnTypeWillChangeMethodTargetOnly($op->attributeNames, 'function', $op->attributeEntries);
        AttributeNames::assertDeprecatedTargetAllowed($op->attributeNames, 'function', $op->attributeEntries);
        if ($expr instanceof Op\Expr\Closure) {
            foreach ($closureUseVars as $useVar) {
                $name = $this->boundVariableName($useVar);
                $slot = $funcBlock->getVarSlot($useVar, false);
                $op->closureCaptures[] = [
                    'name' => $name,
                    'slot' => $slot,
                    'byRef' => $useVar->byRef,
                ];
            }
        } elseif ($expr instanceof Op\Expr\ArrowFunction) {
            // Zend auto-captures outer locals/parameters (zend_compile.c); nested fn-in-fn needs
            // explicit closureCaptures so VM/JIT bind at creation time (#4944, #4952).
            $seenCaptureSlots = [];
            $seenCaptureNames = [];
            foreach ($funcBlock->args as $captureOperand) {
                $slot = (int) $funcBlock->args[$captureOperand];
                if (isset($seenCaptureSlots[$slot])) {
                    continue;
                }
                $name = Block::resolveVariableName($captureOperand);
                if (null === $name || '' === $name) {
                    continue;
                }
                if (in_array($name, $funcBlock->paramNames, true)) {
                    continue;
                }
                // $this is bound via Closure::$this / LLVM __object__*, not use()-style capture
                // (zend_closures.c). Auto-capturing it made AOT emit (object, value) ABI + an
                // unreachable assign-from-capture that segfaulted on invoke (#28612).
                if ('this' === $name) {
                    continue;
                }
                $seenCaptureSlots[$slot] = true;
                $seenCaptureNames[$name] = true;
                $funcBlock->closureCaptureSlots[$slot] = true;
                $funcBlock->closureCaptureSlotNames[$slot] = $name;
                $op->closureCaptures[] = [
                    'name' => $name,
                    'slot' => $slot,
                    'byRef' => false,
                ];
            }
            // Transitive capture: nested arrow functions may reference variables from
            // grandparent+ scopes that this arrow function doesn't directly use. Propagate
            // those captures upward so the VM can bind them at creation time (#24690).
            foreach ($funcBlock->opCodes as $innerOp) {
                if (OpCode::TYPE_CLOSURE !== $innerOp->type || [] === $innerOp->closureCaptures) {
                    continue;
                }
                foreach ($innerOp->closureCaptures as $innerCapture) {
                    $capName = $innerCapture['name'];
                    if (isset($seenCaptureNames[$capName])) {
                        continue;
                    }
                    if (in_array($capName, $funcBlock->paramNames, true)) {
                        continue;
                    }
                    if ('this' === $capName) {
                        continue;
                    }
                    $seenCaptureNames[$capName] = true;
                    $syntheticOp = new \PHPCfg\Operand\Variable(
                        new \PHPCfg\Operand\Literal($capName)
                    );
                    $slot = $funcBlock->getVarSlot($syntheticOp, true);
                    if (isset($seenCaptureSlots[$slot])) {
                        continue;
                    }
                    $seenCaptureSlots[$slot] = true;
                    $funcBlock->closureCaptureSlots[$slot] = true;
                    $funcBlock->closureCaptureSlotNames[$slot] = $capName;
                    $op->closureCaptures[] = [
                        'name' => $capName,
                        'slot' => $slot,
                        'byRef' => $innerCapture['byRef'],
                    ];
                }
            }
        }

        return [$op];
    }

    private function boundVariableName(Operand\BoundVariable $useVar): string
    {
        if ($useVar->name instanceof Operand\Literal && is_string($useVar->name->value)) {
            return $useVar->name->value;
        }
        $this->throwCompileLogic('Closure use() variable name must be a literal');
    }

    protected function shouldStubClosureForBootstrap(): bool
    {
        $userScript = Config::getenv('PHP_COMPILER_AOT_USER_SCRIPT');
        if ('1' === $userScript || 'true' === strtolower((string) $userScript)) {
            return false;
        }

        return '1' === (string) Config::getenv('PHP_COMPILER_VENDOR_PRELINK')
            || '1' === (string) Config::getenv('PHP_COMPILER_SELFHOST_AOT');
    }

    protected function markFunctionGenerator(Block $block): void
    {
        if (null === $block->func || null === $this->seen) {
            return;
        }
        foreach ($this->seen as $cfgBlock) {
            $compiled = $this->seen[$cfgBlock];
            if ($compiled->func === $block->func) {
                $compiled->isGenerator = true;
            }
        }
    }

    protected function markGeneratorIfNeeded(Op\CallableOp $callable, Block $funcBlock): void
    {
        if (Block::containsGeneratorOpcodesInCallableBody($funcBlock) || $this->callableOpHasSourceYield($callable)) {
            $this->markFunctionGenerator($funcBlock);
        }
    }

    protected function callableOpHasSourceYield(Op\CallableOp $callable): bool
    {
        if (!$callable instanceof Op) {
            return false;
        }
        $attrs = $callable->getAttributes();

        return isset($attrs[GeneratorYieldSourceMarker::ATTRIBUTE])
            && $attrs[GeneratorYieldSourceMarker::ATTRIBUTE];
    }

    protected function funcDeclReturnTypeIsGenerator(CfgFunc $func): bool
    {
        $returnType = $func->returnType;
        if ($returnType instanceof Op\Type\Literal) {
            return 'Generator' === $returnType->name;
        }
        if ($returnType instanceof Op\Type\Reference) {
            $decl = $returnType->declaration;

            return $decl instanceof Operand\Literal
                && is_string($decl->value)
                && 'Generator' === $decl->value;
        }

        return false;
    }

    protected function funcDeclReturnTypeIsNever(CfgFunc $func): bool
    {
        $returnType = $func->returnType;
        if ($returnType instanceof Op\Type\Never_) {
            return true;
        }
        if ($returnType instanceof Op\Type\Literal && 'never' === strtolower($returnType->name)) {
            return true;
        }

        return false;
    }

    private function isNeverFunctionCallOp(Op $op): bool
    {
        if ($op instanceof Op\Expr\FuncCall) {
            $name = $this->staticNameFromOperand($op->name);
        } elseif ($op instanceof Op\Expr\NsFuncCall) {
            $name = $this->staticNameFromOperand($op->nsName);
        } else {
            return false;
        }
        if (null === $name) {
            return false;
        }

        return isset($this->neverFunctionNames[strtolower($name)]);
    }

    /**
     * Ops after a call to a `: never` function in the same CFG block are unreachable (#4117).
     *
     * @param Op[] $ops
     */
    private function isUnreachableAfterNeverCall(Op $op, array $ops, int $index): bool
    {
        for ($j = $index - 1; $j >= 0; --$j) {
            if ($this->isNeverFunctionCallOp($ops[$j])) {
                return true;
            }
            if (!$ops[$j] instanceof Op\Expr) {
                return false;
            }
        }

        return false;
    }

}
