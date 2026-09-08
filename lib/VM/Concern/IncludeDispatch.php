<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * VM TYPE_INCLUDE dispatch (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}::runFramesInner include/require case body
 * (php-src Zend/zend_vm_def.h ZEND_INCLUDE_OR_EVAL; zend_execute.c include/require;
 * main/fopen_wrappers.c failed-opening messages). Concern trait — same namespace as
 * parent so relative Frame / OpCode helpers resolve. Move-only; no new C ABI.
 *
 * @return Frame|int|null Frame → restart runFramesInner; int → return from
 *         runFramesInner (SUCCESS/EXCEPTION/FAILURE); null → opcode complete.
 */
trait IncludeDispatch
{
    /**
     * Execute TYPE_INCLUDE / require / *_once for the current opcode.
     *
     * @return Frame|int|null
     */
    private function executeIncludeDispatch(Frame $frame, OpCode $op): Frame|int|null
    {
        $file = null;
        if (null !== $op->arg3 && isset($frame->block->literalIncludePaths[$op->arg3])) {
            $file = $frame->block->literalIncludePaths[$op->arg3];
        } elseif (null !== $op->arg3 && isset($frame->block->deployIncludePaths[$op->arg3])) {
            $spec = $frame->block->deployIncludePaths[$op->arg3];
            $file = $spec['compile'] ?? \PHPCompiler\Web\DeployRoot::resolvePathWithSuffix(
                $spec['rel'],
                $spec['fallback'],
                $spec['suffix']
            );
        }
        if (null === $file) {
            try {
                $file = $frame->scope[$op->arg1]->toString();
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
            }
        }

        $kind = $op->includeKind ?? OpCode::INCLUDE_KIND_INCLUDE_ONCE;
        $once = $kind === OpCode::INCLUDE_KIND_INCLUDE_ONCE || $kind === OpCode::INCLUDE_KIND_REQUIRE_ONCE;
        $isRequire = $kind === OpCode::INCLUDE_KIND_REQUIRE || $kind === OpCode::INCLUDE_KIND_REQUIRE_ONCE;

        if (VM\PathSupport::isEmptyPath($file)) {
            $catchFrame = $this->dispatchVmValueError(
                new \ValueError(VM\PathSupport::EMPTY_PATH_VALUE_ERROR_MESSAGE),
                $frame
            );
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            return null;
        }

        $resolved = $this->resolveIncludeFilename($file, $frame);
        if (null === $resolved) {
            // Zend two-step: stream Warning, then Failed opening Warning (include)
            // or Error (require) with include_path (#30029; fopen_wrappers.c).
            $keyword = VM\VmInclude::kindKeyword($kind);
            $includePath = \PHPCompiler\ext\standard\VmIncludePath::get();
            $scriptFile = '' !== $frame->scriptPath ? $frame->scriptPath : null;
            $this->context->errors->triggerError(
                VM\VmInclude::failedToOpenStreamMessage($keyword, $file),
                VM\ErrorReporter::E_WARNING,
                $scriptFile,
                $this->context,
                $frame
            );
            if ($isRequire) {
                $catchFrame = $this->dispatchEngineThrow(
                    $frame,
                    $this->makeEngineError(
                        VM\VmInclude::failedOpeningRequiredMessage($file, $includePath),
                        'Error'
                    )
                );
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
                return null;
            }
            $this->context->errors->triggerError(
                VM\VmInclude::failedOpeningForInclusionMessage($keyword, $file, $includePath),
                VM\ErrorReporter::E_WARNING,
                $scriptFile,
                $this->context,
                $frame
            );
            if (null !== $op->arg2 && isset($frame->scope[$op->arg2])) {
                $frame->scope[$op->arg2]->bool(false);
            }
            return null;
        }

        // Project builds refuse includes outside the compile-unit file map (#36382).
        $allow = $this->context->runtime->aotIncludeAllowlist ?? null;
        if (is_array($allow) && [] !== $allow
            && !VM\ProjectIncludeAllowlist::isAllowed($resolved, $allow)
        ) {
            $catchFrame = $this->dispatchEngineThrow(
                $frame,
                $this->makeEngineError(
                    VM\ProjectIncludeAllowlist::denyMessage($resolved),
                    'Error'
                )
            );
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            return null;
        }

        if ($once && $this->context->isCompileUnitLoaded($resolved)) {
            if (null !== $op->arg2 && isset($frame->scope[$op->arg2])) {
                // Zend: include_once/require_once return bool(true) when the file was already included.
                $frame->scope[$op->arg2]->bool(true);
            }
            return null;
        }
        $this->context->recordIncludedFile($resolved);
        $this->context->scriptStack->push($resolved);
        try {
            $parsed = $this->context->runtime->parseAndCompileFile($resolved, true);
        } catch (\Throwable $e) {
            $this->context->scriptStack->pop();
            if (VM\VmInclude::isCatchableSyntaxParseThrowable($e)) {
                $catchFrame = $this->dispatchIncludeParseError($e, $resolved, $frame);
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
                return null;
            }
            throw $e;
        }
        if (null === $parsed) {
            $this->context->scriptStack->pop();
            $detail = $this->context->runtime->formatParseAndCompileNullDetail(null)
                ?? Runtime::getLastParseFailure()
                ?? 'syntax error';
            $catchFrame = $this->dispatchIncludeParseError(
                new \ParseError(VM\VmInclude::normalizeSyntaxParseMessage($detail)),
                $resolved,
                $frame
            );
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            return null;
        }
        $new = $parsed->getFrame($this->context, $frame);
        $new->ephemeral = true;
        // ZEND_INCLUDE_OR_EVAL copies EX(This) into the included op_array (#31903).
        $this->inheritIncludeThis($new, $frame);
        // …and called_scope for self/static/parent in the included unit (#31913).
        $this->inheritIncludeClassScope($new, $frame);
        // Resume the caller via the run stack (like a call); keep $frame as a scope donor only.
        $new->parent = null;
        if (null !== $op->arg2) {
            $new->returnVar = $frame->scope[$op->arg2];
            $new->returnVar->int(1);
        }
        $this->context->push($frame);
        return $new;
    }
}
