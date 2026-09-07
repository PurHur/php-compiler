<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\BuiltinByRefParams;
use PHPCompiler\Func;
use PHPCompiler\VM\ScriptExit;
use PHPCompiler\VM\TypedPropertyReadSignal;
use PHPCompiler\VM\Variable;

/**
 * Internal builtin handler execute + ASSIGN copyFrom bridges (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}: {@code executeInternalHandler} and
 * {@code assignCopyFrom} (php-src Zend/zend_execute.c ZEND_DO_ICALL / ZEND_ASSIGN
 * with ArrayAccess offsetSet and typed/computed property writes). Concern trait —
 * same namespace as parent so relative Frame / OpCode helpers resolve. Move-only;
 * no new C ABI.
 */
trait InternalHandlerExecuteAndAssignCopy
{
    /**
     * Run an internal builtin handler; bridge native Error/Throwable into user catch (#3648).
     */
    private function executeInternalHandler(Frame $handlerFrame, Frame $callerFrame): ?Frame
    {
        // Void builtin calls omit returnVar; handlers must still run validation/throws (#4866).
        if (null === $handlerFrame->returnVar) {
            $handlerFrame->returnVar = new Variable();
        }
        if ($handlerFrame->handler instanceof Func\Internal) {
            foreach (BuiltinByRefParams::forFunction($handlerFrame->handler->getName()) as $idx) {
                if (!isset($handlerFrame->calledArgs[$idx])) {
                    continue;
                }
                $catchFrame = $this->enforceReadonlyPropertyWrite(
                    $handlerFrame->calledArgs[$idx],
                    $callerFrame
                );
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
                $catchFrame = $this->enforceFinalPropertyWrite(
                    $handlerFrame->calledArgs[$idx],
                    $callerFrame
                );
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
            }
        }
        try {
            $this->builtinHandlerFrameForTrace = $handlerFrame;
            $handlerFrame->handler->execute($handlerFrame);

            return null;
        } catch (\DivisionByZeroError $e) {
            return $this->dispatchVmDivisionByZeroError($e, $callerFrame);
        } catch (\ArithmeticError $e) {
            return $this->dispatchVmArithmeticError($e, $callerFrame);
        } catch (\ArgumentCountError $e) {
            return $this->dispatchVmArgumentCountError($e, $callerFrame);
        } catch (\TypeError $e) {
            return $this->dispatchVmTypeError($e, $callerFrame);
        } catch (VM\ExtSimdJsonValueError $e) {
            return $this->dispatchVmSimdJsonValueError($e, $callerFrame);
        } catch (\ValueError $e) {
            return $this->dispatchVmValueError($e, $callerFrame);
        } catch (\AssertionError $e) {
            return $this->dispatchVmAssertionError($e, $callerFrame);
        } catch (VM\NativeFiberError $e) {
            return $this->dispatchVmFiberError($e, $callerFrame);
        } catch (VM\NativeFiberStackOverflow $e) {
            return $this->dispatchVmFiberStackOverflowFromNative($e, $callerFrame);
        } catch (\ParseError $e) {
            return $this->dispatchVmParseError($e, $callerFrame);
        } catch (\CompileError $e) {
            return $this->dispatchVmCompileError($e, $callerFrame);
        } catch (\ReflectionException $e) {
            return $this->dispatchVmReflectionException($e, $callerFrame);
        } catch (\JsonException $e) {
            return $this->dispatchVmJsonException($e, $callerFrame);
        } catch (\DOMException $e) {
            return $this->dispatchVmDomException($e, $callerFrame);
        } catch (\SodiumException $e) {
            return $this->dispatchVmSodiumException($e, $callerFrame);
        } catch (\IntlException $e) {
            return $this->dispatchVmIntlException($e, $callerFrame);
        } catch (\RedisException $e) {
            return $this->dispatchVmRedisException($e, $callerFrame);
        } catch (\RarException $e) {
            return $this->dispatchVmRarException($e, $callerFrame);
        } catch (VM\ExtSimdJsonException $e) {
            return $this->dispatchVmSimdJsonException($e, $callerFrame);
        } catch (\FFI\ParserException $e) {
            return $this->dispatchVmFfiException($e, $callerFrame, true);
        } catch (\FFI\Exception $e) {
            return $this->dispatchVmFfiException($e, $callerFrame, false);
        } catch (VM\NativeDateInvalidTimeZoneException $e) {
            return $this->dispatchVmDateInvalidTimeZoneException($e, $callerFrame);
        } catch (VM\NativeDateMalformedStringException $e) {
            return $this->dispatchVmDateMalformedStringException($e, $callerFrame);
        } catch (VM\NativeDateInvalidOperationException $e) {
            return $this->dispatchVmDateInvalidOperationException($e, $callerFrame);
        } catch (VM\NativeDateMalformedIntervalException $e) {
            return $this->dispatchVmDateMalformedIntervalException($e, $callerFrame);
        } catch (VM\NativeDateMalformedPeriodStringException $e) {
            return $this->dispatchVmDateMalformedPeriodStringException($e, $callerFrame);
        } catch (VM\NativeDateRangeError $e) {
            return $this->dispatchVmDateRangeError($e, $callerFrame);
        } catch (VM\NativeDateObjectError $e) {
            return $this->dispatchVmDateObjectError($e, $callerFrame);
        } catch (\Error $e) {
            return $this->dispatchVmError($e->getMessage(), $callerFrame);
        } catch (VM\GeneratorUncaughtThrow $e) {
            return $this->dispatchUncaughtGeneratorThrow($e->thrown, $callerFrame, $handlerFrame);
        } catch (VM\FiberUncaughtThrow $e) {
            return $this->dispatchUncaughtGeneratorThrow($e->thrown, $callerFrame, $handlerFrame);
        } catch (TypedPropertyReadSignal $signal) {
            $catchFrame = $this->findCatchFrameForThrow($callerFrame, $signal->errorObject);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            $this->raiseUncaughtException($signal->errorObject);

            return null;
        } catch (ScriptExit $e) {
            throw $e;
        } catch (\BadMethodCallException $e) {
            // During (string)/echo __toString coercion, rethrow so TYPE_CAST_STRING (etc.)
            // can dispatch into the *user* try/catch. Bridging here returns a catch frame that
            // invokeMagicToString turns into MagicMethodInvocationAborted — which CAST swallows,
            // leaving "" / undefined ($s) instead of BadMethodCallException (#24907 CachingIterator).
            if ($this->context->coercingObjectToString) {
                throw $e;
            }

            return $this->dispatchVmBadMethodCallException($e, $callerFrame);
        } catch (\OutOfBoundsException $e) {
            return $this->dispatchVmOutOfBoundsException($e, $callerFrame);
        } catch (\UnexpectedValueException $e) {
            return $this->dispatchVmUnexpectedValueException($e, $callerFrame);
        } catch (\PDOException $e) {
            return $this->dispatchVmPDOException($e, $callerFrame);
        } catch (\SQLite3Exception $e) {
            return $this->dispatchVmSQLite3Exception($e, $callerFrame);
        } catch (\mysqli_sql_exception $e) {
            return $this->dispatchVmMysqliSqlException($e, $callerFrame);
        } catch (\PharException $e) {
            return $this->dispatchVmPharException($e, $callerFrame);
        } catch (\SoapFault $e) {
            return $this->dispatchVmSoapFault($e, $callerFrame);
        } catch (\RedisClusterException $e) {
            return $this->dispatchVmRedisClusterException($e, $callerFrame);
        } catch (\RuntimeException $e) {
            return $this->dispatchVmRuntimeException($e, $callerFrame);
        } catch (\InvalidArgumentException $e) {
            return $this->dispatchVmInvalidArgumentException($e, $callerFrame);
        } catch (\OutOfRangeException $e) {
            // SplDoublyLinkedList OOB — before LogicException (parent) (#31553).
            return $this->dispatchVmOutOfRangeException($e, $callerFrame);
        } catch (\LogicException $e) {
            return $this->dispatchVmLogicException($e, $callerFrame);
        } catch (VM\NativeRequestParseBodyException $e) {
            return $this->dispatchVmRequestParseBodyException($e, $callerFrame);
        } catch (\Uri\WhatWg\InvalidUrlException $e) {
            return $this->dispatchVmInvalidUrlException($e, $callerFrame);
        } catch (\Uri\InvalidUriException $e) {
            return $this->dispatchVmInvalidUriException($e, $callerFrame);
        } catch (\Filter\FilterFailedException $e) {
            return $this->dispatchVmFilterFailedException($e, $callerFrame);
        } catch (VM\MagicMethodInvocationAborted) {
            $this->clearTryCatchUnwindState();
            $callerFrame->call = null;
            $this->clearOutgoingCallState($callerFrame);
            $callerFrame->suppressNextEcho = true;
            ++$callerFrame->pos;

            return null;
        } catch (VM\BuiltinCallbackCatchRedirect $redirect) {
            return $this->resumeAfterBuiltinCallbackCatchRedirect($redirect);
        } catch (\Exception $e) {
            return $this->dispatchVmEngineException($e->getMessage(), $callerFrame);
        } finally {
            $this->builtinHandlerFrameForTrace = null;
        }
    }

    /** ASSIGN to ArrayAccess lvalue — dispatch deferred offsetSet TypeError (#8949). */
    private function assignCopyFrom(Variable $dst, Variable $src, Frame $frame): ?Frame
    {
        try {
            $resolved = $dst->resolveIndirect();
            if (null !== $resolved->objectPropertyOwner && null !== $resolved->objectPropertyName) {
                try {
                    VM\ObjectComputedPropertySupport::rejectReadOnlyPropertyWrite(
                        $resolved->objectPropertyOwner,
                        $resolved->objectPropertyName
                    );
                    VM\DatePeriodSupport::rejectReadOnlyPropertyWrite(
                        $resolved->objectPropertyOwner,
                        $resolved->objectPropertyName
                    );
                } catch (\Error $e) {
                    return $this->dispatchVmError($e->getMessage(), $frame);
                }
                if (VM\ObjectComputedPropertySupport::tryAssign(
                    $resolved->objectPropertyOwner,
                    $resolved->objectPropertyName,
                    $src,
                    $this->context
                )) {
                    return null;
                }
            }
            $dst->copyFrom($src);

            return null;
        } catch (\TypeError $e) {
            $resolved = $dst->resolveIndirect();
            if ($resolved->isArrayAccessOffset()) {
                $dst->null();
            }

            return $this->dispatchVmTypeError($e, $frame);
        } catch (\ValueError $e) {
            return $this->dispatchVmValueError($e, $frame);
        } catch (\OutOfBoundsException $e) {
            // SplFixedArray OOB under PROFILE≥8.4 — before RuntimeException (parent) (#28819).
            $resolved = $dst->resolveIndirect();
            if ($resolved->isArrayAccessOffset()) {
                $dst->null();
            }

            return $this->dispatchVmOutOfBoundsException($e, $frame);
        } catch (\RuntimeException $e) {
            // ArrayAccess dim write (e.g. SplFixedArray OOB) — same bridge as method calls (#21994).
            $resolved = $dst->resolveIndirect();
            if ($resolved->isArrayAccessOffset()) {
                $dst->null();
            }

            return $this->dispatchVmRuntimeException($e, $frame);
        } catch (\OutOfRangeException $e) {
            // SplDoublyLinkedList OOB dim — before LogicException (parent) (#31553).
            $resolved = $dst->resolveIndirect();
            if ($resolved->isArrayAccessOffset()) {
                $dst->null();
            }

            return $this->dispatchVmOutOfRangeException($e, $frame);
        } catch (\LogicException $e) {
            $resolved = $dst->resolveIndirect();
            if ($resolved->isArrayAccessOffset()) {
                $dst->null();
            }

            return $this->dispatchVmLogicException($e, $frame);
        } catch (\Error $e) {
            return $this->dispatchVmError($e->getMessage(), $frame);
        }
    }
}
