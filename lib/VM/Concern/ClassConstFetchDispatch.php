<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\Variable;

/**
 * VM TYPE_CLASS_CONST_FETCH dispatch (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}::runFramesInner class-const-fetch case body
 * (php-src Zend/zend_vm_def.h ZEND_FETCH_CLASS_CONSTANT; zend_execute.c
 * zend_fetch_class_constant / ::class pseudo-const). Concern trait — same
 * namespace as parent so relative Frame / OpCode helpers resolve. Move-only;
 * no new C ABI.
 *
 * @return Frame|int|null Frame → restart runFramesInner; int → return from
 *         runFramesInner (SUCCESS/EXCEPTION/FAILURE); null → opcode complete.
 */
trait ClassConstFetchDispatch
{
    /**
     * Execute TYPE_CLASS_CONST_FETCH for the current opcode.
     *
     * @return Frame|int|null
     */
    private function executeClassConstFetchDispatch(Frame $frame, OpCode $op): Frame|int|null
    {
        $memberNameRaw = $frame->scope[$op->arg3]->toString();
        $classOperand = $frame->scope[$op->arg2]->resolveIndirect();
        if ($op->classConstFetchOnObject) {
            if ('class' === strtolower($memberNameRaw)) {
                $fqcn = $this->resolveClassPseudoConstFromOperand($classOperand);
                if (null !== $fqcn) {
                    $frame->scope[$op->arg1]->string($fqcn);
                    return null;
                }
                $catchFrame = $this->dispatchVmTypeError(
                    new \TypeError(
                        VM\EnumCaseSupport::classPseudoConstTypeErrorMessage($classOperand)
                    ),
                    $frame
                );
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
                return null;
            }
            if (Variable::TYPE_OBJECT !== $classOperand->type) {
                $catchFrame = $this->dispatchVmTypeError(
                    new \TypeError(
                        VM\EnumCaseSupport::classPseudoConstTypeErrorMessage($classOperand)
                    ),
                    $frame
                );
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
                return null;
            }
            $frame->scope[$op->arg1]->string($classOperand->toObject()->class->name);
            return null;
        }
        if (Variable::TYPE_OBJECT === $classOperand->type) {
            $classEntry = $classOperand->toObject()->class;
            $traitConstFrame = $this->enforceDirectTraitConstAccess($classEntry, $memberNameRaw, $frame);
            if (null !== $traitConstFrame) {
                return $traitConstFrame;
            }
            $constKey = ClassConstName::key($memberNameRaw);
            if (isset($classEntry->constants[$constKey])
                && ClassConstName::matchesDeclared(
                    $memberNameRaw,
                    $this->declaredClassConstName($classEntry, $constKey)
                )
            ) {
                $visFrame = $this->enforceClassConstVisibility($classEntry, $memberNameRaw, $frame);
                if (null !== $visFrame) {
                    return $visFrame;
                }
            }
            $staticVisFrame = $this->enforceStaticPropertyReadVisibility(
                strtolower($classEntry->name),
                $memberNameRaw,
                $frame
            );
            if (null !== $staticVisFrame) {
                return $staticVisFrame;
            }
            try {
                if (!$this->copyClassConstOrStaticPropertyByName(
                    $classEntry,
                    $memberNameRaw,
                    $frame->scope[$op->arg1],
                    $frame
                )) {
                    $catchFrame = $this->dispatchVmError(
                        "Undefined constant {$classEntry->name}::{$memberNameRaw}",
                        $frame
                    );
                    if (null !== $catchFrame) {
                        return $catchFrame;
                    }

                    return self::EXCEPTION;
                }
            } catch (\Error $e) {
                $catchFrame = $this->dispatchVmError($e->getMessage(), $frame);
                if (null !== $catchFrame) {
                    return $catchFrame;
                }

                return self::EXCEPTION;
            }
            return null;
        }
        try {
            $classOperand = $frame->scope[$op->arg2]->resolveIndirect();
            $constName = strtolower($frame->scope[$op->arg3]->toString());
            if (Variable::TYPE_OBJECT === $classOperand->type && 'class' === $constName) {
                $frame->scope[$op->arg1]->string($classOperand->toObject()->class->name);
                return null;
            }
            // String class name only — reject bool/int/null/array (#30059).
            $className = VM\InstanceOfClassName::resolveClassNamePreservingCase(
                $classOperand
            );
            $lcClass = $this->resolveClassScopeName($className, $frame);
        } catch (\Error $e) {
            $catchFrame = $this->dispatchVmError($e->getMessage(), $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            return self::EXCEPTION;
        } catch (\LogicException $e) {
            $catchFrame = $this->dispatchVmError($e->getMessage(), $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            return self::EXCEPTION;
        }
        if (!isset($this->context->classes[$lcClass])) {
            if ('self' !== strtolower($className) && 'static' !== strtolower($className)) {
                $this->context->autoloadClass($className);
            }
        }
        if (!isset($this->context->classes[$lcClass])) {
            // `ConstName::class` when ConstName is a user constant (Zend zend_compile.c; #5440).
            if ('class' === $constName && null !== $this->context->constantFetch($className)) {
                $frame->scope[$op->arg1]->string($className);
                return null;
            }
            if ('class' === $constName) {
                $builtinName = BuiltinTypeClassConstant::classNameForTypeOperand($className);
                if (null !== $builtinName) {
                    $frame->scope[$op->arg1]->string($builtinName);
                    return null;
                }
                $builtinFn = BuiltinFunctionClassConstant::functionNameForClassOperand($className);
                if (null !== $builtinFn) {
                    $frame->scope[$op->arg1]->string($builtinFn);
                    return null;
                }
                if ('self' !== strtolower($className) && 'static' !== strtolower($className)) {
                    // Foo::class is a pure name literal — Zend resolves it
                    // without the class being declared (#16828).
                    $frame->scope[$op->arg1]->string(ltrim($className, '\\'));
                    return null;
                }
            }

            // Missing class on Class::CONST / Enum::Case — catchable Error
            // (zend_execute.c), not LogicException via raise() (#28480).
            $catchFrame = $this->dispatchVmError(
                $this->classNotFoundMessage($className),
                $frame
            );
            if (null !== $catchFrame) {
                return $catchFrame;
            }

            return self::EXCEPTION;
        }
        $classEntry = $this->context->classes[$lcClass];
        $traitConstFrame = $this->enforceDirectTraitConstAccess($classEntry, $memberNameRaw, $frame);
        if (null !== $traitConstFrame) {
            return $traitConstFrame;
        }
        $constKey = ClassConstName::key($memberNameRaw);
        if (isset($classEntry->constants[$constKey])
            && ClassConstName::matchesDeclared(
                $memberNameRaw,
                $this->declaredClassConstName($classEntry, $constKey)
            )
        ) {
            $visFrame = $this->enforceClassConstVisibility($classEntry, $memberNameRaw, $frame);
            if (null !== $visFrame) {
                return $visFrame;
            }
        }
        $staticVisFrame = $this->enforceStaticPropertyReadVisibility($lcClass, $memberNameRaw, $frame);
        if (null !== $staticVisFrame) {
            return $staticVisFrame;
        }
        if ('class' === $constName) {
            $frame->scope[$op->arg1]->string(
                $this->resolveClassPseudoConstDisplayName($className, $frame)
            );
            return null;
        }
        try {
            if (!$this->copyClassConstOrStaticPropertyByName(
                $classEntry,
                $memberNameRaw,
                $frame->scope[$op->arg1],
                $frame
            )) {
                $catchFrame = $this->dispatchVmError(
                    "Undefined constant {$className}::{$memberNameRaw}",
                    $frame
                );
                if (null !== $catchFrame) {
                    return $catchFrame;
                }

                return self::EXCEPTION;
            }
        } catch (\Error $e) {
            $catchFrame = $this->dispatchVmError($e->getMessage(), $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }

            return self::EXCEPTION;
        }

        return null;
    }
}
