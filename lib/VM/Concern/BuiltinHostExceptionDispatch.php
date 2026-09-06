<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\Variable;

/**
 * VM builtin/host exception bridges into catch / uncaught dispatch (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}: {@code dispatchUncaughtGeneratorThrow}
 * through {@code dispatchVmTypeError}, then {@code dispatchVmArgumentCountError}
 * through {@code dispatchVmFiberStackOverflowFromNative} (php-src
 * Zend/zend_exceptions.c EG(exception) materialization; ext/* throw sites
 * bridged into VM Throwable classes). Concern trait — same namespace as parent
 * so relative Frame / OpCode helpers resolve. Move-only; no new C ABI.
 */
trait BuiltinHostExceptionDispatch
{
    private function dispatchUncaughtGeneratorThrow(
        Variable $thrown,
        Frame $callerFrame,
        ?Frame $resumeHandlerFrame = null,
    ): ?Frame {
        if (null !== $resumeHandlerFrame) {
            VM\ExceptionTrace::captureOnGeneratorResumeUncaught(
                $this->context,
                $callerFrame,
                $resumeHandlerFrame,
                $thrown
            );
        }
        $catchFrame = $this->findCatchFrameForThrow($callerFrame, $thrown);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $this->raiseUncaughtException($thrown);

        return null;
    }

    /** Attach builtin throw trace then dispatch to user catch / fatal (#11677, #14369). */
    private function dispatchBuiltinThrowable(Frame $callerFrame, Variable $thrown): ?Frame
    {
        if (null !== $this->builtinHandlerFrameForTrace) {
            VM\ExceptionTrace::captureOnBuiltinThrow(
                $this->context,
                $callerFrame,
                $this->builtinHandlerFrameForTrace,
                $thrown
            );
        } else {
            VM\ExceptionTrace::captureOnThrow($this->context, $callerFrame, $thrown);
        }
        $catchFrame = $this->findCatchFrameForThrow($callerFrame, $thrown);
        if (null !== $catchFrame) {
            if ($this->stashPropertyHookSetExternalCatch($callerFrame, $catchFrame)) {
                return null;
            }

            return $catchFrame;
        }
        $this->raiseUncaughtException($thrown);

        return null;
    }

    /** Bridge native Exception from builtins (e.g. Generator::rewind after run, #5195). */
    private function dispatchVmEngineException(string $message, Frame $frame): ?Frame
    {
        $thrown = $this->makeEngineError($message, 'Exception');

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /**
     * Bridge host RequestParseBodyException into the VM RequestParseBodyException class (#5965).
     *
     * php-src: ext/standard/http.c — PHP_FUNCTION(request_parse_body).
     */
    private function dispatchVmRequestParseBodyException(\Throwable $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeRequestParseBodyException(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge host Uri\InvalidUriException into VM catch handlers (#21468). */
    private function dispatchVmInvalidUriException(\Uri\InvalidUriException $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeInvalidUriException(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge host Filter\FilterFailedException into VM catch handlers (#28131). */
    private function dispatchVmFilterFailedException(\Filter\FilterFailedException $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeFilterFailedException(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge host Uri\WhatWg\InvalidUrlException into VM catch handlers (#21468). */
    private function dispatchVmInvalidUrlException(\Uri\WhatWg\InvalidUrlException $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeInvalidUrlException(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge native LogicException from stdlib builtins into user catch handlers (#4866). */
    private function dispatchVmLogicException(\LogicException $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeLogicException(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge InvalidArgumentException from SPL builtins (#16917, ext/spl/spl_iterators.c). */
    private function dispatchVmInvalidArgumentException(\InvalidArgumentException $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeInvalidArgumentException(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge BadMethodCallException from SPL builtins (#13379, ext/spl/spl_iterators.c). */
    private function dispatchVmBadMethodCallException(\BadMethodCallException $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeBadMethodCallException(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge OutOfBoundsException from SPL builtins (#13561, ext/spl/spl_array.c). */
    private function dispatchVmOutOfBoundsException(\OutOfBoundsException $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeOutOfBoundsException(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge OutOfRangeException from SPL builtins (#31553, ext/spl/spl_dllist.c). */
    private function dispatchVmOutOfRangeException(\OutOfRangeException $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeOutOfRangeException(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge native PDOException from ext/pdo builtins into user catch handlers (#19830, re-#3367, #22455). */
    private function dispatchVmPDOException(\PDOException $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $errorInfo = null;
        if (isset($error->errorInfo) && \is_array($error->errorInfo)) {
            $errorInfo = $error->errorInfo;
        }
        $thrown = VM\BuiltinExceptionSupport::materializePDOException(
            $this->context,
            $error->getMessage(),
            $file,
            $line,
            (int) $error->getCode(),
            $errorInfo
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge native SoapFault from ext/soap builtins (#20124, #20219). */
    private function dispatchVmSoapFault(\SoapFault $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $faultcode = isset($error->faultcode) ? (string) $error->faultcode : '';
        $faultstring = isset($error->faultstring) ? (string) $error->faultstring : $error->getMessage();
        $faultactor = isset($error->faultactor) ? (string) $error->faultactor : '';
        $detail = $error->detail ?? null;
        $name = isset($error->_name) ? (string) $error->_name : '';
        $faultcodens = isset($error->faultcodens) ? (string) $error->faultcodens : '';
        $thrown = VM\BuiltinExceptionSupport::materializeSoapFault(
            $this->context,
            $error->getMessage(),
            $file,
            $line,
            $faultcode,
            $faultstring,
            $faultactor,
            $detail,
            $name,
            $faultcodens
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge native FFI\Exception / FFI\ParserException from ext/ffi builtins (#4420). */
    private function dispatchVmFfiException(\FFI\Exception $error, Frame $frame, bool $parser): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeFfiException(
            $this->context,
            $error->getMessage(),
            $file,
            $line,
            $parser || $error instanceof \FFI\ParserException
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge native SQLite3Exception from ext/sqlite3 builtins (#19862). */
    private function dispatchVmSQLite3Exception(\SQLite3Exception $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeSQLite3Exception(
            $this->context,
            $error->getMessage(),
            $file,
            $line,
            (int) $error->getCode()
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge native mysqli_sql_exception from ext/mysqli builtins (#21803, #21815, #22456). */
    private function dispatchVmMysqliSqlException(\mysqli_sql_exception $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $sqlstate = '00000';
        if (\method_exists($error, 'getSqlState')) {
            $sqlstate = (string) $error->getSqlState();
        }
        $thrown = VM\BuiltinExceptionSupport::materializeMysqliSqlException(
            $this->context,
            $error->getMessage(),
            $file,
            $line,
            (int) $error->getCode(),
            $sqlstate
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge native PharException from ext/phar builtins (#21232). */
    private function dispatchVmPharException(\PharException $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializePharException(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge native RuntimeException from SPL file builtins (#6393, ext/spl/spl_directory.c). */
    private function dispatchVmRuntimeException(\RuntimeException $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeRuntimeException(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge native UnexpectedValueException from DirectoryIterator (#3635 family). */
    private function dispatchVmUnexpectedValueException(\UnexpectedValueException $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeUnexpectedValueException(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /**
     * Bridge native TypeError from VM internals into user catch handlers (#3445).
     */
    private function dispatchVmTypeError(\TypeError $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeTypeError(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /**
     * Bridge native ArgumentCountError from stdlib builtins into user catch handlers (#4034).
     */
    private function dispatchVmArgumentCountError(\ArgumentCountError $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeArgumentCountError(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /**
     * Bridge native DivisionByZeroError from numeric ops into user catch handlers (#3562, #3371).
     */
    private function dispatchVmDivisionByZeroError(\DivisionByZeroError $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeDivisionByZeroError(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /**
     * Bridge native ArithmeticError from stdlib builtins into user catch handlers (#4724).
     */
    private function dispatchVmArithmeticError(\ArithmeticError $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeArithmeticError(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /**
     * Bridge native ValueError from stdlib builtins into user catch handlers (#3763).
     */
    private function dispatchVmValueError(\ValueError $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeValueError(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge native AssertionError from assert() into user catch handlers (#3316). */
    private function dispatchVmAssertionError(\AssertionError $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeAssertionError(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /**
     * Coerce a runtime operand to string for property/var names (Zend zend_operators.c, #6206).
     *
     * @return array{0: string|null, 1: Frame|null}
     */
    private function coerceRuntimeOperandToString(Variable $operand, Frame $frame): array
    {
        try {
            return [$operand->resolveIndirect()->toString($this, $frame), null];
        } catch (\Error $e) {
            return [null, $this->dispatchVmError($e->getMessage(), $frame)];
        }
    }

    /**
     * Reject property names starting with null byte (Zend zend_verify_property_name, #5136).
     *
     * @return ?Frame catch frame when handled; null when name valid
     */
    private function enforcePropertyName(string $name, Frame $frame): ?Frame
    {
        $message = VM\PropertyNameSupport::leadingNullByteMessage($name);
        if (null === $message) {
            return null;
        }

        return $this->dispatchVmError($message, $frame);
    }

    /**
     * Bridge VM Error throws (enum clone guard, echo __toString, etc.) into user catch handlers (#3554, #3564).
     */
    /** Zend object_and_properties_init unknown class message (zend_execute.c). */
    private function classNotFoundMessage(string $className): string
    {
        return sprintf('Class "%s" not found', $className);
    }

    private function dispatchVmError(string $message, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeError($this->context, $message, $file, $line);

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /**
     * Run lazy ghost/proxy init; convert host Error/TypeError into catchable VM throwables (#29151).
     *
     * Captures the init throwable on the lazy object (getLazyInitializationException) before
     * dispatch — ensureInitialized()'s finally clears lazyInitializingObject before this catch.
     */
    private function ensureLazyObjectInitialized(ObjectEntry $object, Frame $frame): ?Frame
    {
        try {
            VM\LazyObjectSupport::ensureInitialized($this, $object);
        } catch (\TypeError $e) {
            $thrown = $this->makeEngineError($e->getMessage(), 'TypeError');
            VM\LazyObjectSupport::captureLazyInitException($object, $thrown);

            return $this->dispatchVmTypeError($e, $frame);
        } catch (\Error $e) {
            $thrown = $this->makeEngineError($e->getMessage());
            VM\LazyObjectSupport::captureLazyInitException($object, $thrown);

            return $this->dispatchVmError($e->getMessage(), $frame);
        }

        return null;
    }

    /** php-src ext/dom/php_dom.c + DatePeriod write handlers (#15550, #20605, #26154). */
    private function enforceDomDocumentReadOnlyPropertyWrite(
        ObjectEntry $object,
        string $name,
        Frame $frame
    ): ?Frame {
        try {
            VM\ObjectComputedPropertySupport::rejectReadOnlyPropertyWrite($object, $name);
            VM\DatePeriodSupport::rejectReadOnlyPropertyWrite($object, $name);
        } catch (\Error $e) {
            return $this->dispatchVmError($e->getMessage(), $frame);
        }

        return null;
    }

    private function dispatchVmCompileError(\CompileError $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeCompileError(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /**
     * Catchable CompileError from eval() — Zend zend_throw_exception file shape (#25114).
     *
     * php-src: zif_eval / zend_eval_string — exception file is parent(line) : eval()'d code.
     */
    private function dispatchVmEvalCompileError(\CompileError $error, Frame $frame): ?Frame
    {
        $evalLine = 1;
        if ($error instanceof \PHPCompiler\Compiler\CompileFatal && $error->sourceLine > 0) {
            // wrapEvalCode prepends "<?php\n" — map compiler line back to eval body (#22796).
            $evalLine = $error->sourceLine > 1 ? $error->sourceLine - 1 : max(1, $error->sourceLine);
        } elseif ($error->getCode() > 0) {
            $evalLine = $error->getCode();
        }
        [$file, $line] = VM\ExceptionSupport::evalFatalSite($frame, $evalLine);
        $thrown = VM\BuiltinExceptionSupport::materializeCompileError(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /**
     * Zend E_COMPILE_ERROR during eval() — uncatchable CLI fatal (#22922).
     *
     * php-src: zend_error_noreturn(E_COMPILE_ERROR, …) from zend_inheritance.c;
     * file shape Command line code(N) : eval()'d code (zif_eval / #4410).
     *
     * @return never
     */
    private function raiseEvalCompileFatal(\CompileError $error, Frame $frame): never
    {
        $evalLine = 1;
        if ($error instanceof \PHPCompiler\Compiler\CompileFatal && $error->sourceLine > 0) {
            // wrapEvalCode prepends "<?php\n" — map compiler line back to eval body (#22796).
            $evalLine = $error->sourceLine > 1 ? $error->sourceLine - 1 : max(1, $error->sourceLine);
        }
        [$file, $line] = VM\ExceptionSupport::evalFatalSite($frame, $evalLine);
        if ($this->context->isolatedPhpFunctionInvoke || $this->context->bubbleUncaughtToNative) {
            throw $error;
        }
        $this->context->errors->recordLastError(
            VM\ErrorReporter::E_COMPILE_ERROR,
            $error->getMessage(),
            $file,
            $line
        );
        VM\ErrorReporter::writeCliErrorOutput(
            VM\ErrorReporter::E_COMPILE_ERROR,
            $error->getMessage(),
            $file,
            $line,
            $this->context->errors->getDisplayErrors()
        );
        throw new ScriptExit(255);
    }

    /**
     * Duplicate class/interface/trait/enum — Zend E_COMPILE_ERROR, not catchable LogicException (#31110).
     *
     * php-src: zend_error_noreturn(E_COMPILE_ERROR, "Cannot declare %s %s, because the name is already in use")
     *
     * @return never
     */
    private function raiseDuplicateClassLikeDeclareFatal(string $kind, string $name, Frame $frame): never
    {
        $error = new \CompileError(sprintf(
            'Cannot declare %s %s, because the name is already in use',
            $kind,
            $name
        ));
        if (VmEval::isEvalScriptPath((string) $frame->scriptPath)) {
            $this->raiseEvalCompileFatal($error, $frame);
        }
        $this->raiseClassDeclareCompileFatal($error, $frame);
    }

    /**
     * Zend E_COMPILE_ERROR during class declare (include/require / top-level) — uncatchable (#25384).
     *
     * @return never
     */
    private function raiseClassDeclareCompileFatal(\CompileError $error, Frame $frame): never
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        if ($this->context->isolatedPhpFunctionInvoke || $this->context->bubbleUncaughtToNative) {
            throw $error;
        }
        $this->context->errors->recordLastError(
            VM\ErrorReporter::E_COMPILE_ERROR,
            $error->getMessage(),
            $file,
            $line
        );
        VM\ErrorReporter::writeCliErrorOutput(
            VM\ErrorReporter::E_COMPILE_ERROR,
            $error->getMessage(),
            $file,
            $line,
            $this->context->errors->getDisplayErrors()
        );
        throw new ScriptExit(255);
    }

    private function dispatchVmParseError(\ParseError $error, Frame $frame): ?Frame
    {
        $evalLine = $error->getCode() > 0 ? $error->getCode() : 1;
        [$file, $line] = VM\ExceptionSupport::evalFatalSite($frame, $evalLine);
        $thrown = VM\BuiltinExceptionSupport::materializeParseError(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /**
     * include/require syntax failure — catchable ParseError in the included file (#32154).
     *
     * php-src: Zend/zend_execute.c ZEND_INCLUDE_OR_EVAL; zend_compile_file parse failures.
     */
    private function dispatchIncludeParseError(\Throwable $error, string $includedFile, Frame $frame): ?Frame
    {
        $message = VM\VmInclude::syntaxParseMessage($error);
        $line = VM\VmInclude::syntaxParseLine($error);
        $this->context->errors->recordLastError(
            VM\ErrorReporter::E_PARSE,
            $message,
            $includedFile,
            $line
        );
        $thrown = VM\BuiltinExceptionSupport::materializeParseError(
            $this->context,
            $message,
            $includedFile,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge native ReflectionException from reflection builtins into user catch handlers (#7344). */
    private function dispatchVmReflectionException(\ReflectionException $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeReflectionException(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge native JsonException from ext/json builtins into user catch handlers (#3281). */
    private function dispatchVmJsonException(\JsonException $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeJsonException(
            $this->context,
            $error->getMessage(),
            $file,
            $line,
            $error->getCode()
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge native DOMException from ext/dom builtins into user catch handlers (#15430). */
    private function dispatchVmDomException(\DOMException $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeDomException(
            $this->context,
            $error->getMessage(),
            $file,
            $line,
            $error->getCode()
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge native SodiumException from ext/sodium builtins into user catch handlers (#15531). */
    private function dispatchVmSodiumException(\SodiumException $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeSodiumException(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge native IntlException from ext/intl builtins into user catch handlers (#22577). */
    private function dispatchVmIntlException(\IntlException $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeIntlException(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge native RedisException from ext/redis builtins into user catch handlers (#6098). */
    private function dispatchVmRedisException(\RedisException $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeRedisException(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge native RedisClusterException into user catch handlers (#28094). */
    private function dispatchVmRedisClusterException(\RedisClusterException $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeRedisClusterException(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge native RarException from ext/rar builtins into user catch handlers (#6237). */
    private function dispatchVmRarException(\RarException $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeRarException(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    private function dispatchVmSimdJsonException(
        VM\ExtSimdJsonException $error,
        Frame $frame
    ): ?Frame {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeSimdJsonException(
            $this->context,
            $error->getMessage(),
            $file,
            $line,
            $error->getCode()
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    private function dispatchVmSimdJsonValueError(
        VM\ExtSimdJsonValueError $error,
        Frame $frame
    ): ?Frame {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeSimdJsonValueError(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge native DateInvalidTimeZoneException from date builtins into user catch handlers (#7279). */
    private function dispatchVmDateInvalidTimeZoneException(
        VM\NativeDateInvalidTimeZoneException $error,
        Frame $frame
    ): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeDateInvalidTimeZoneException(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge malformed DateTime strings from date builtins into user catch handlers (#7113). */
    private function dispatchVmDateMalformedStringException(
        VM\NativeDateMalformedStringException $error,
        Frame $frame
    ): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeDateMalformedStringException(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge illegal date operations from date builtins into user catch handlers (#6048). */
    private function dispatchVmDateInvalidOperationException(
        VM\NativeDateInvalidOperationException $error,
        Frame $frame
    ): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeDateInvalidOperationException(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge malformed DateInterval specs into DateMalformedIntervalStringException (#20779). */
    private function dispatchVmDateMalformedIntervalException(
        VM\NativeDateMalformedIntervalException $error,
        Frame $frame
    ): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeDateMalformedIntervalStringException(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge malformed DatePeriod ISO8601 specs from date builtins into user catch handlers (#7296). */
    private function dispatchVmDateMalformedPeriodStringException(
        VM\NativeDateMalformedPeriodStringException $error,
        Frame $frame
    ): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeDateMalformedPeriodStringException(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge DateRangeError from date builtins into user catch handlers (#7276). */
    private function dispatchVmDateRangeError(VM\NativeDateRangeError $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeDateRangeError(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge DateObjectError from date builtins into user catch handlers (#7276). */
    private function dispatchVmDateObjectError(VM\NativeDateObjectError $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeDateObjectError(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /**
     * Bridge native FiberError from fiber lifecycle operations into user catch handlers (#4372).
     */
    private function dispatchVmFiberError(VM\NativeFiberError $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeFiberError(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /**
     * Guard fiber call depth before entering a callee frame (#7267; php-src zend_call_stack_size_error).
     */
    private function guardFiberStackBeforeCall(Frame $frame): ?Frame
    {
        if (null === $this->context->currentFiber || !VM\FiberStackLimit::wouldOverflow($this->context)) {
            return null;
        }

        return $this->dispatchVmFiberStackOverflow($frame);
    }

    private function dispatchVmFiberStackOverflow(Frame $frame): ?Frame
    {
        $fiber = $this->context->currentFiber;
        if (null !== $fiber) {
            [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
            $thrown = VM\BuiltinExceptionSupport::materializeFiberStackOverflow(
                $this->context,
                VM\FiberStackLimit::stackSizeErrorMessage(),
                $file,
                $line
            );
            $this->context->pendingException = $thrown;
            for ($handler = $frame; null !== $handler; $handler = $handler->parent) {
                if ($handler->fiberState !== $fiber && $this->findFiberState($handler) !== $fiber) {
                    break;
                }
                $catchFrame = $this->dispatchCatchForHandlerFrame($handler, $frame);
                if (null !== $catchFrame) {
                    $catchFrame->fiberState = $fiber;
                    $fiber->frame = $catchFrame;

                    return $catchFrame;
                }
            }
            $this->clearTryCatchUnwindState();
            $fiber->status = FiberState::STATUS_TERMINATED;
            $fiber->frame = null;
            $fiber->hasReturnValue = false;
            $fiber->threw = true;

            throw new VM\NativeFiberStackOverflow(VM\FiberStackLimit::stackSizeErrorMessage());
        }

        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeFiberStackOverflow(
            $this->context,
            VM\FiberStackLimit::stackSizeErrorMessage(),
            $file,
            $line
        );
        $catchFrame = $this->findCatchFrameForThrow($frame, $thrown);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $this->raiseUncaughtException($thrown);

        return null;
    }

    private function dispatchVmFiberStackOverflowFromNative(
        VM\NativeFiberStackOverflow $error,
        Frame $frame
    ): ?Frame {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeFiberStackOverflow(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

}
