<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * VM TYPE_SCRIPT_MAGIC / TYPE_TICK_SCOPE_* / TYPE_TICKS dispatch (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}::runFramesInner case bodies
 * (php-src Zend/zend_compile.c zend_compile_const for __FILE__/__DIR__/__LINE__/
 * __COMPILER_HALT_OFFSET__; Zend/zend_execute.c tick handlers /
 * zend_declare_ticks; Zend/zend_vm_def.h ZEND_TICKS). Concern trait — same
 * namespace as parent so relative Frame / OpCode helpers resolve. Move-only;
 * no new C ABI.
 *
 * @return Frame|int|null Frame → restart runFramesInner; int → return from
 *         runFramesInner (SUCCESS/EXCEPTION/FAILURE); null → opcode complete.
 */
trait ScriptMagicAndTickDispatch
{
    /**
     * Execute TYPE_SCRIPT_MAGIC / tick-scope / TYPE_TICKS for the current opcode.
     *
     * @return Frame|int|null
     */
    private function executeScriptMagicAndTickDispatch(Frame $frame, OpCode $op): Frame|int|null
    {
        switch ($op->type) {
        case OpCode::TYPE_SCRIPT_MAGIC:
            $dst = $frame->scope[$op->arg1];
            if (OpCode::SCRIPT_MAGIC_HALT_OFFSET === $op->arg3) {
                $offset = $this->context->runtime->compiler->getHaltCompilerOffset();
                if (null === $offset) {
                    return $this->raise('Undefined constant "__COMPILER_HALT_OFFSET__"', $frame);
                }
                $dst->int($offset);
                return null;
            }
            if (OpCode::SCRIPT_MAGIC_LINE === $op->arg3) {
                $line = null !== $op->arg2 ? (int) $op->arg2 : 0;
                if ($line < 1) {
                    $line = 1;
                }
                $dst->int($line);
                return null;
            }
            $script = '' !== $frame->scriptPath
                ? $frame->scriptPath
                : $this->context->scriptStack->current();
            if ('' === $script) {
                return $this->raise('__DIR__/__FILE__ used without script context', $frame);
            }
            if (OpCode::SCRIPT_MAGIC_DIR === $op->arg3) {
                $dst->string(dirname($script));
            } else {
                $dst->string($script);
            }
            return null;
        case OpCode::TYPE_TICK_SCOPE_ENTER:
            $this->context->tickIntervalStack[] = $this->context->tickInterval;
            $this->context->tickInterval = max(0, (int) $op->arg1);
            $this->context->tickCounter = $this->context->tickInterval > 0
                ? $this->context->tickInterval
                : 0;
            return null;
        case OpCode::TYPE_TICK_SCOPE_SET:
            $this->context->tickInterval = max(0, (int) $op->arg1);
            $this->context->tickCounter = $this->context->tickInterval > 0
                ? $this->context->tickInterval
                : 0;
            return null;
        case OpCode::TYPE_TICK_SCOPE_LEAVE:
            if ([] !== $this->context->tickIntervalStack) {
                $this->context->tickInterval = array_pop($this->context->tickIntervalStack);
            } else {
                $this->context->tickInterval = 0;
            }
            $this->context->tickCounter = $this->context->tickInterval > 0
                ? $this->context->tickInterval
                : 0;
            return null;
        case OpCode::TYPE_TICKS:
            $this->maybeRunTick();
            return null;
        default:
            throw new \LogicException(
                'ScriptMagicAndTickDispatch: unexpected opcode ' . opcode_type_name($op->type)
            );
        }
    }
}
