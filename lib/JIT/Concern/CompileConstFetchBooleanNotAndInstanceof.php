<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPCompiler\JIT\Variable;

/**
 * Constant fetch, boolean-not, instanceof, and `in` opcode lowering for JIT/AOT (#36387).
 *
 * Extracted from {@see CompileBlockInternal}: {@code TYPE_BOOLEAN_NOT},
 * {@code TYPE_CONST_FETCH}, {@code TYPE_INSTANCEOF}, {@code TYPE_IN}.
 * Move-only; no IR shape change.
 *
 * php-src: Zend/zend_vm_def.h (ZEND_BOOL_NOT / ZEND_FETCH_CONSTANT / ZEND_INSTANCEOF),
 * Zend/zend_operators.c, Zend/zend_constants.c (CONST_DEPRECATED) —
 * move-only Concern extract; no new C ABI.
 */
trait CompileConstFetchBooleanNotAndInstanceof
{
    private function compileConstFetchBooleanNotAndInstanceofOp(Block $block, OpCode $op): void
    {
        switch ($op->type) {
            case OpCode::TYPE_BOOLEAN_NOT:
                $from = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                if ($from->type === Variable::TYPE_NATIVE_BOOL) {
                    $value = $this->context->helper->loadValue($from);
                } else {
                    $value = $this->context->castToBool($this->context->helper->loadValue($from));
                }
                $__right = $value->typeOf()->constInt(1, false);
                $result = $this->context->builder->bitwiseXor($value, $__right);
                // Force: php-cfg leaves `var_dump(!$o)` on a dead temp while ARG_SEND is
                // remapped to the not-result — empty usages skip the store (#32471 / #32293).
                $this->assignOperandValue($block->getOperand($op->arg1), $result, true);
                break;
            case OpCode::TYPE_CONST_FETCH:
                $value = null;
                if (!is_null($op->arg3)) {
                    // try NS constant fetch
                    $value = $this->context->constantFetch($block->getOperand($op->arg3));
                }
                if (is_null($value)) {
                    $value = $this->context->constantFetch($block->getOperand($op->arg2));
                }
                if (is_null($value)) {
                    $name = $block->getOperand($op->arg2);
                    $label = $name instanceof Operand\Literal ? (string) $name->value : get_class($name);
                    if (null !== $op->arg3) {
                        $ns = $block->getOperand($op->arg3);
                        if ($ns instanceof Operand\Literal) {
                            $label = (string) $ns->value.'\\'.$label;
                        }
                    }
                    $bundleConst = $this->jitFoldPhpCompilerBundleConstant($label);
                    if (null !== $bundleConst) {
                        $this->assignOperand($block->getOperand($op->arg1), $bundleConst);
                        break;
                    }
                    throw new \RuntimeException('Undefined constant "'.$label.'"');
                }
                // CONST_DEPRECATED globals (E_STRICT, ASSERT_*, …) — Zend zend_constants.c (#29229).
                $fetchNameOp = null !== $op->arg3
                    ? $block->getOperand($op->arg3)
                    : $block->getOperand($op->arg2);
                if ($fetchNameOp instanceof Operand\Literal && \is_string($fetchNameOp->value)) {
                    $constFetchName = $fetchNameOp->value;
                    $depMeta = $this->context->runtime->vmContext->globalConstDeprecated[strtolower($constFetchName)] ?? null;
                    if (null !== $depMeta) {
                        \PHPCompiler\JIT\DeprecatedCallGuard::emitGlobalConstFetch(
                            $this->context,
                            $depMeta,
                            $constFetchName
                        );
                    }
                }
                $this->assignOperand($block->getOperand($op->arg1), $value);
                break;
            case OpCode::TYPE_INSTANCEOF:
                $expr = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                $unionEncoded = $op->instanceofUnionTypes;
                if (null !== $unionEncoded && '' !== $unionEncoded) {
                    $types = array_values(array_filter(explode('|', $unionEncoded), static fn (string $t): bool => '' !== $t));
                    $result = $this->context->type->object->emitInstanceOfUnion($expr, $types);
                    $this->assignOperand($block->getOperand($op->arg1), $result);
                    break;
                }
                $keyword = $op->instanceofScopeKeyword;
                if (null !== $keyword && '' !== $keyword) {
                    // `static` late-binds to $this / called class (Zend ZEND_INSTANCEOF).
                    // AOT must not bake the trait/declaring name — that makes trait
                    // `instanceof static` always false (#31746).
                    if (
                        'static' === $keyword
                        && \PHPCompiler\JIT\LateStaticBindingHelper::useRuntimeLateStatic($this->context)
                    ) {
                        $classIdVal = \PHPCompiler\JIT\ClassConstFetchHelper::emitStaticKeywordClassIdForPseudoConst(
                            $this->context->type->object,
                            $block
                        );
                        $result = \PHPCompiler\JIT\InstanceOfHelper::emitWithRuntimeClassId(
                            $this->context,
                            $expr,
                            $classIdVal
                        );
                        $this->assignOperand($block->getOperand($op->arg1), $result);
                        break;
                    }
                    // Trait flatten compiles this block with traitComposingClassName set (#31729).
                    $resolved = $this->resolveJitStaticScopeClass(
                        $block,
                        new Operand\Literal($keyword)
                    );
                    $result = $this->context->type->object->emitInstanceOf($expr, $resolved);
                    $this->assignOperand($block->getOperand($op->arg1), $result);
                    break;
                }
                $result = \PHPCompiler\JIT\InstanceOfHelper::emit(
                    $this->context,
                    $expr,
                    $block->getOperand($op->arg3)
                );
                $this->assignOperand($block->getOperand($op->arg1), $result);
                break;
            case OpCode::TYPE_IN:
                $needle = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                $haystack = $this->context->getVariableFromOp($block->getOperand($op->arg3));
                $found = \PHPCompiler\JIT\InOperatorHelper::emitContains($this->context, $needle, $haystack);
                $this->assignOperand($block->getOperand($op->arg1), $found);
                break;
            default:
                throw new \LogicException(
                    'compileConstFetchBooleanNotAndInstanceofOp: unexpected opcode '.$op->type
                );
        }
    }
}
