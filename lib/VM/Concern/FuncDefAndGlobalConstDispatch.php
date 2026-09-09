<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Func;
use PHPCompiler\ext\standard\VmEval;
use PHPCompiler\VM\Variable;

/**
 * VM TYPE_FUNCDEF / TYPE_DECLARE_GLOBAL_CONST dispatch (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}::runFramesInner declare case bodies
 * (php-src Zend/zend_compile.c zend_compile_func_decl / zend_compile_const;
 * Zend/zend_vm_def.h ZEND_DECLARE_FUNCTION; Zend/zend_constants.c
 * zend_register_constant / "Constant already defined"). Concern trait —
 * same namespace as parent so relative Frame / OpCode helpers resolve.
 * Move-only; no new C ABI.
 *
 * @return Frame|int|null Frame → restart runFramesInner; int → return from
 *         runFramesInner (SUCCESS/EXCEPTION/FAILURE); null → opcode complete.
 */
trait FuncDefAndGlobalConstDispatch
{
    /**
     * Execute TYPE_FUNCDEF / TYPE_DECLARE_GLOBAL_CONST for the current opcode.
     *
     * @return Frame|int|null
     */
    private function executeFuncDefAndGlobalConstDispatch(Frame $frame, OpCode $op): Frame|int|null
    {
        switch ($op->type) {
        case OpCode::TYPE_FUNCDEF:
            VM\RedundantTrueFalseUnionCheck::assertFunctionBlock(
                $op->block1,
                $frame,
                $op->sourceLocation
            );
            VM\RedundantIterableUnionCheck::assertFunctionBlock(
                $op->block1,
                $frame,
                $op->sourceLocation
            );
            $name = $frame->scope[$op->arg1]->toString();
            $lcname = strtolower($name);
            if (isset($this->context->functions[$lcname])) {
                $existing = $this->context->functions[$lcname];
                $prevFile = '';
                $prevLine = 0;
                if ($existing instanceof Func\PHP && null !== $existing->sourceLocation) {
                    $prevFile = $existing->sourceLocation->filename;
                    $prevLine = $existing->sourceLocation->startLine;
                }
                $message = ('' !== $prevFile && 'unknown' !== $prevFile && $prevLine > 0)
                    ? sprintf(
                        'Cannot redeclare %s() (previously declared in %s:%d)',
                        $name,
                        $prevFile,
                        $prevLine
                    )
                    : sprintf('Cannot redeclare %s()', $name);
                $error = new \CompileError($message);
                // Inside eval(): rethrow so TYPE_EVAL can raiseEvalCompileFatal.
                // Outside eval, uncatchable E_COMPILE_ERROR like Zend (#31109).
                if (VmEval::EVAL_FILENAME === $frame->scriptPath
                    || str_ends_with((string) $frame->scriptPath, VmEval::EVAL_FILENAME)
                ) {
                    throw $error;
                }
                $this->raiseClassDeclareCompileFatal($error, $frame);
            }
            $func = new Func\PHP($name, $op->block1);
            $func->sourceLocation = $op->sourceLocation;
            $func->deprecated = $op->deprecatedMetadata;
            if ([] !== $op->parameterMetadata) {
                $func->parameterMetadata = $op->parameterMetadata;
            }
            if ([] !== $op->attributeNames) {
                $func->attributeNames = $op->attributeNames;
            }
            if ([] !== $op->attributeEntries) {
                $func->attributeEntries = $op->attributeEntries;
            }
            $this->context->declareFunction($func);
            break;
        case OpCode::TYPE_DECLARE_GLOBAL_CONST:
            $name = $frame->scope[$op->arg1]->toString();
            if (isset($frame->block->constants[$op->arg2])) {
                $constValue = new Variable();
                $constValue->copyFrom($frame->block->constants[$op->arg2]);
            } elseif (isset($frame->scope[$op->arg2])) {
                $constValue = new Variable();
                $constValue->copyFrom($frame->scope[$op->arg2]);
            } else {
                throw new \LogicException('Global constant value must be a compile-time constant');
            }
            $constValue = VM\EnumCaseSupport::materializeConstantValue($this->context, $constValue);
            $constFilename = '' !== $frame->scriptPath ? $frame->scriptPath : 'Command line code';
            if (!$this->context->defineConstant($name, $constValue, false, $constFilename)) {
                $line = (int) ($op->globalConstStartLine ?? 0);
                $this->context->errors->triggerError(
                    "Constant {$name} already defined",
                    VM\ErrorReporter::E_WARNING,
                    '' !== $frame->scriptPath ? $frame->scriptPath : null,
                    $this->context,
                    $frame,
                    $line > 0 ? $line : 0
                );
            }
            if (null !== $op->deprecatedMetadata) {
                $this->context->globalConstDeprecated[strtolower($name)] = $op->deprecatedMetadata;
            }
            // PHP 8.5+ attributes on file/namespace constants (#23882).
            if ([] !== $op->attributeEntries) {
                $this->context->globalConstAttributeEntries[strtolower($name)] = $op->attributeEntries;
            } elseif ([] !== $op->attributeNames) {
                $entries = [];
                foreach ($op->attributeNames as $attrName) {
                    $entries[] = new \PHPCompiler\Compiler\AttributeEntry((string) $attrName);
                }
                $this->context->globalConstAttributeEntries[strtolower($name)] = $entries;
            }
            break;
        default:
            throw new \LogicException(
                'FuncDefAndGlobalConstDispatch: unexpected opcode '
                . opcode_type_name($op->type)
            );
        }

        return null;
    }
}
