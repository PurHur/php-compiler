<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmEval;
use PHPCompiler\VM\Variable;

/**
 * VM TYPE_ECHO / TYPE_PRINT / TYPE_EVAL dispatch (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}::runFramesInner echo/print/eval case
 * bodies (php-src Zend/zend_vm_def.h ZEND_ECHO / ZEND_PRINT /
 * ZEND_INCLUDE_OR_EVAL with ZEND_EVAL; zend_execute.c output + eval compile).
 * Concern trait — same namespace as parent so relative Frame / OpCode helpers
 * resolve. Move-only; no new C ABI.
 *
 * @return Frame|int|null Frame → restart runFramesInner; int → return from
 *         runFramesInner (SUCCESS/EXCEPTION/FAILURE); null → opcode complete
 *         or soft-fail after catchable compile/parse error already dispatched.
 */
trait EchoPrintEvalDispatch
{
    /**
     * Execute TYPE_ECHO for the current opcode.
     *
     * @return Frame|int|null
     */
    private function executeEchoDispatch(Frame $frame, OpCode $op): Frame|int|null
    {
        if ($frame->suppressNextEcho) {
            $frame->suppressNextEcho = false;

            return null;
        }
        // echo $this outside object context — Error (zend_execute.c ZEND_ECHO / FETCH_THIS, #31901).
        $catchFrame = $this->guardUnboundThisRead($frame, (int) $op->arg1);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        try {
            if (!VM\SapiOutput::headersSent()) {
                VM\HeaderCallbackQueue::runBeforeOutput($this->context);
            }
            $printed = $this->valueToPrintString(
                $this->readScopeOperandForRuntimeRead($frame, (int) $op->arg1),
                $frame
            );
        } catch (\Error $e) {
            $catchFrame = $this->dispatchVmError($e->getMessage(), $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }

            return null;
        } catch (\TypeError $e) {
            $catchFrame = $this->dispatchVmTypeError($e, $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }

            return null;
        } catch (VM\BuiltinCallbackCatchRedirect $redirect) {
            // __toString throw during echo — do not continue try body (#29521).
            return $this->resumeAfterBuiltinCallbackCatchRedirect($redirect);
        } catch (VM\MagicMethodInvocationAborted) {
            return null;
        }
        $this->releaseVmStatementDeadTemps($frame, (int) $op->arg1);
        $echoFile = '' !== $frame->scriptPath ? $frame->scriptPath : null;
        VM\OutputBuffer::append($printed, $echoFile, (int) ($op->arg2 ?? 0));

        return null;
    }

    /**
     * Execute TYPE_PRINT for the current opcode.
     *
     * @return Frame|int|null
     */
    private function executePrintDispatch(Frame $frame, OpCode $op): Frame|int|null
    {
        // print $this outside object context — Error (zend_execute.c ZEND_PRINT / FETCH_THIS, #31901).
        $catchFrame = $this->guardUnboundThisRead($frame, (int) $op->arg2);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        try {
            if (!VM\SapiOutput::headersSent()) {
                VM\HeaderCallbackQueue::runBeforeOutput($this->context);
            }
            $printFile = '' !== $frame->scriptPath ? $frame->scriptPath : null;
            VM\OutputBuffer::append(
                $this->valueToPrintString($frame->scope[$op->arg2], $frame),
                $printFile,
                (int) ($op->arg3 ?? 0)
            );
            $frame->scope[$op->arg1]->int(1);
        } catch (\Error $e) {
            $catchFrame = $this->dispatchVmError($e->getMessage(), $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }

            return null;
        } catch (\TypeError $e) {
            $catchFrame = $this->dispatchVmTypeError($e, $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }

            return null;
        } catch (VM\BuiltinCallbackCatchRedirect $redirect) {
            // __toString throw during print — do not continue try body (#29521).
            return $this->resumeAfterBuiltinCallbackCatchRedirect($redirect);
        } catch (VM\MagicMethodInvocationAborted) {
            return null;
        }

        return null;
    }

    /**
     * Execute TYPE_EVAL for the current opcode.
     *
     * @return Frame|int|null
     */
    private function executeEvalDispatch(Frame $frame, OpCode $op): Frame|int|null
    {
        $codeVar = $frame->scope[$op->arg2]->resolveIndirect();
        $dest = $frame->scope[$op->arg1];
        if (Variable::TYPE_STRING !== $codeVar->type) {
            return $this->raise('eval() expects a string argument', $frame);
        }
        try {
            $evalResult = VmEval::evalCodeInFrame(
                $this,
                $frame,
                $codeVar->toString()
            );
        } catch (VM\BuiltinCallbackCatchRedirect $redirect) {
            // Outer try matched from nested eval runFrames — resume catch here (#25816).
            return $this->resumeAfterBuiltinCallbackCatchRedirect($redirect);
        } catch (\ParseError $e) {
            $catchFrame = $this->dispatchVmParseError($e, $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }

            return null;
        } catch (\CompileError $e) {
            // php-src: zend_throw_exception(CompileError) is catchable in eval (#25114);
            // zend_inheritance.c zend_error_noreturn(E_COMPILE_ERROR) is not (#22922, #22329).
            if (!VmEval::isCatchableCompileError($e)) {
                $this->raiseEvalCompileFatal($e, $frame);
            }
            $catchFrame = $this->dispatchVmEvalCompileError($e, $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }

            return null;
        }
        $dest->copyFrom($evalResult);

        return null;
    }
}
