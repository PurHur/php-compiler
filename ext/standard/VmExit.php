<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Frame;
use PHPCompiler\VM;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\ResourceSupport;
use PHPCompiler\VM\ScriptExit;
use PHPCompiler\VM\ShutdownQueue;
use PHPCompiler\VM\TypeCheck;
use PHPCompiler\VM\Variable;
use PHPCompiler\Web\Superglobals;

/** VM lowering for exit/die (issue #269). */
final class VmExit
{
    public static function terminate(?Variable $arg, ?Frame $frame = null, ?Variable $messageArg = null): never
    {
        if (null !== $messageArg) {
            self::echoExitMessage($messageArg, $frame);
        }
        $status = self::resolveStatus($arg, $frame, null !== $messageArg);
        $ctx = Superglobals::getActiveContext();
        if (null !== $ctx) {
            ShutdownQueue::run($ctx);
        }
        throw new ScriptExit($status);
    }

    public static function resolveStatus(?Variable $arg, ?Frame $frame = null, bool $twoArgForm = false): int
    {
        if (null === $arg) {
            return 0;
        }
        $v = $arg->resolveIndirect();
        if (Variable::TYPE_STRING === $v->type) {
            if ($twoArgForm) {
                return is_numeric($v->toString()) ? (int) $v->toString() : 0;
            }
            echo $v->toString();

            return 0;
        }
        if (Variable::TYPE_INTEGER === $v->type) {
            return $v->toInt();
        }
        if (Variable::TYPE_NULL === $v->type) {
            if ($twoArgForm) {
                return 0;
            }
            // PHP 8.4+ exit()/die() string|int: null → E_DEPRECATED then status 0 (#29575).
            // Pre-8.4 construct form exits 0 silently. strict_types → TypeError.
            if (CompilerVersion::supportsExitFunctionForm()) {
                if (self::callerStrictTypes($frame)) {
                    throw self::typeErrorForStatus($v);
                }
                VmNullStringParamDeprecation::emit($frame, 'exit', 0, 'status', 'string|int');
            }

            return 0;
        }
        // PHP 8.4+ exit()/die() string|int: bool coerces to int status (true→1), not string (#29573).
        // Pre-8.4 construct form stringifies (true→"1" + exit 0). strict_types → TypeError.
        if (Variable::TYPE_BOOLEAN === $v->type) {
            if ($twoArgForm) {
                return $v->toInt();
            }
            if (CompilerVersion::supportsExitFunctionForm()) {
                if (self::callerStrictTypes($frame)) {
                    throw self::typeErrorForStatus($v);
                }

                return $v->toInt();
            }
            echo $v->toString();

            return 0;
        }
        if (Variable::TYPE_FLOAT === $v->type) {
            if ($twoArgForm) {
                return self::floatStatusToInt($v->toFloat(), $frame);
            }
            // PHP 8.4+ exit()/die() string|int: float → int status + precision DEP (#29574).
            // Pre-8.4 construct form stringifies (1.5→"1.5" + exit 0).
            if (CompilerVersion::supportsExitFunctionForm()) {
                if (self::callerStrictTypes($frame)) {
                    throw self::typeErrorForStatus($v);
                }

                return self::floatStatusToInt($v->toFloat(), $frame);
            }
            echo $v->toString();

            return 0;
        }
        if (Variable::TYPE_ARRAY === $v->type) {
            // PHP 8.4+ exit()/die() are typed string|int — arrays TypeError (#22492 / #4704).
            // Pre-8.4 construct form soft-coerces with Array-to-string warning (#5441).
            if ($twoArgForm || CompilerVersion::supportsExitFunctionForm()) {
                throw self::typeErrorForStatus($v);
            }
            $vm = VM::running();
            echo null !== $vm ? $vm->coerceVariableToString($v, $frame) : 'Array';

            return 0;
        }
        if (Variable::TYPE_ENUM_CASE === $v->type) {
            // php-src has no ExitStatus; any enum status → Error like Zend (#28500, re-#28200 / #7294).
            $case = $v->toEnumCase();
            throw new \Error(
                'Object of class '.$case->enumClass->name.' could not be converted to string'
            );
        }
        if (Variable::TYPE_OBJECT === $v->type) {
            $obj = $v->toObject();
            if (EnumCaseSupport::isEnumCase($obj)) {
                throw new \Error(
                    'Object of class '.$obj->class->name.' could not be converted to string'
                );
            }
            if ($twoArgForm) {
                throw self::typeErrorForStatus($v);
            }
            // PHP 8.4+ ZPP string|int: resources and non-Stringable objects → TypeError (#22492).
            // Stringable / __toString still accepted (#18469).
            if (CompilerVersion::supportsExitFunctionForm()) {
                if (ResourceSupport::isResourceObject($obj)) {
                    throw self::typeErrorForStatus($v);
                }
                $vm = VM::running();
                if (null === $vm || !$vm->hasInstanceMethod($obj->class, '__tostring')) {
                    throw self::typeErrorForStatus($v);
                }
                echo $vm->coerceVariableToString($v, $frame);

                return 0;
            }
            $vm = VM::running();
            echo null !== $vm ? $vm->coerceVariableToString($v, $frame) : 'Object';

            return 0;
        }

        throw self::typeErrorForStatus($v);
    }

    private static function echoExitMessage(Variable $messageArg, ?Frame $frame): void
    {
        $v = $messageArg->resolveIndirect();
        if (Variable::TYPE_STRING === $v->type) {
            echo $v->toString();

            return;
        }
        if (Variable::TYPE_NULL === $v->type) {
            return;
        }
        if (Variable::TYPE_FLOAT === $v->type || Variable::TYPE_BOOLEAN === $v->type || Variable::TYPE_INTEGER === $v->type) {
            echo $v->toString();

            return;
        }
        if (Variable::TYPE_ARRAY === $v->type) {
            $vm = VM::running();
            echo null !== $vm ? $vm->coerceVariableToString($v, $frame) : 'Array';

            return;
        }
        if (Variable::TYPE_ENUM_CASE === $v->type) {
            throw new \Error(
                'Object of class '.$v->toEnumCase()->enumClass->name.' could not be converted to string'
            );
        }
        if (Variable::TYPE_OBJECT === $v->type && EnumCaseSupport::isEnumCase($v->toObject())) {
            throw new \Error(
                'Object of class '.$v->toObject()->class->name.' could not be converted to string'
            );
        }

        throw new \TypeError(sprintf(
            'exit(): Argument #2 ($message) must be of type string, %s given',
            TypeCheck::typeNameForConstraint($v->type)
        ));
    }

    private static function typeErrorForStatus(Variable $value): \TypeError
    {
        return new \TypeError(sprintf(
            'exit(): Argument #1 ($status) must be of type string|int, %s given',
            self::statusTypeName($value)
        ));
    }

    private static function statusTypeName(Variable $value): string
    {
        // Zend zend_zval_type_name / zend_zval_value_name: bool → true/false,
        // legacy Resource wrappers → lowercase "resource" (#29594 / #29559 / #6975).
        return EnumCaseSupport::typeNameForTypeErrorActual($value);
    }

    private static function callerStrictTypes(?Frame $frame): bool
    {
        if (null === $frame) {
            return false;
        }
        if (null !== $frame->block && $frame->block->strictTypes) {
            return true;
        }

        return InternalStrictArg::isCallerStrict($frame);
    }

    /**
     * Truncate float→int like Zend zend_dval_to_lval; E_DEPRECATED on precision loss (#29574).
     */
    private static function floatStatusToInt(float $value, ?Frame $frame): int
    {
        $ctx = null !== $frame ? $frame->vmContext : null;
        if (null === $ctx) {
            $vm = VM::running();
            $ctx = $vm?->context;
        }
        if (null !== $ctx) {
            VmMath::warnFloatToIntPrecisionLoss($value, $ctx, $frame);
        }

        return VmMath::floatToZendLong($value);
    }
}
