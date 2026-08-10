<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\ScriptExit;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitOperandTypeLabel;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * exit() — PHP 8.4 proper function form (paren calls only; bare exit; stays a construct).
 *
 * php-src: Zend/zend_builtin_functions.stub.php — function exit(string|int $status = 0): never
 * php-src: Zend/zend_builtin_functions.c — ZEND_FUNCTION(exit) ZEND_PARSE_PARAMETERS_START(0, 1)
 */
final class exit_ extends Internal
{
    public function __construct()
    {
        parent::__construct('exit');
    }

    public function execute(Frame $frame): void
    {
        self::invokeFromFrame($frame, 'exit');
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return self::jitCall($context, 'exit', ...$args);
    }

    public static function jitCall(Context $context, string $function, JITVariable ...$args): Value
    {
        self::jitInvoke($context, $function, ...$args);
        $ptrType = $context->getTypeFromString('__value__*');

        return $ptrType->constNull();
    }

    public static function invokeFromFrame(Frame $frame, string $function): void
    {
        // php-src ZEND_PARSE_PARAMETERS_START(0, 1) — no phantom $message (#23957).
        self::assertAtMostOneArg($frame, $function);
        $args = $frame->calledArgs;
        $status = \array_key_exists(0, $args) ? $args[0] : null;
        if (\array_key_exists(0, $args)) {
            self::validateStatusArg($args[0], $frame, $function);
        }
        $userFrame = $frame->parent ?? $frame;
        VmExit::terminate($status, $userFrame, null);
    }

    private static function assertAtMostOneArg(Frame $frame, string $function): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(\sprintf(
                '%s() expects at most 1 argument, %d given',
                $function,
                $argc
            ));
        }
    }

    private static function validateStatusArg(Variable $arg, Frame $frame, string $function): void
    {
        $strict = null !== $frame->parent && $frame->parent->block->strictTypes;
        if (!$strict) {
            return;
        }
        $v = $arg->resolveIndirect();
        if (Variable::TYPE_STRING === $v->type
            || Variable::TYPE_INTEGER === $v->type
            || Variable::TYPE_BOOLEAN === $v->type
            || Variable::TYPE_FLOAT === $v->type) {
            return;
        }
        // Zend rejects enum cases at object-to-string conversion (Error), not ZPP (#7214).
        if (Variable::TYPE_ENUM_CASE === $v->type) {
            return;
        }
        if (Variable::TYPE_OBJECT === $v->type) {
            return;
        }

        throw new \TypeError(\sprintf(
            '%s(): Argument #1 ($status) must be of type string|int, %s given',
            $function,
            EnumCaseSupport::typeNameForVariable($v)
        ));
    }

    private static function jitInvoke(Context $context, string $function, JITVariable ...$args): void
    {
        // php-src ZEND_PARSE_PARAMETERS_START(0, 1) — no phantom $message (#23957).
        if (\count($args) > 1) {
            TypeErrorRaise::registerDeclarations($context);
            TypeErrorRaise::ensureLinked($context);
            ExceptionBridge::emitArgumentCountError(
                $context,
                \sprintf('%s() expects at most 1 argument, %d given', $function, \count($args))
            );

            return;
        }
        $status = $args[0] ?? null;
        if ($context->callerStrictTypes && null !== $status) {
            self::jitRequireStringOrIntStatus($context, $status, $function);
        }

        if (null !== $status) {
            ScriptExit::emit($context, $status);
        } else {
            // Bare exit()/die() — default status 0; must not look like exit(null) (#29575).
            ScriptExit::emitLibcExitWithStatus(
                $context,
                $context->getTypeFromString('int64')->constInt(0, false)
            );
        }
    }

    private static function jitRequireStringOrIntStatus(Context $context, JITVariable $arg, string $function): void
    {
        if (\in_array($arg->type, [
            JITVariable::TYPE_STRING,
            JITVariable::TYPE_NATIVE_LONG,
            JITVariable::TYPE_NATIVE_DOUBLE,
            JITVariable::TYPE_NATIVE_BOOL,
        ], true)) {
            return;
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return;
        }
        if (null !== JitOperandTypeLabel::compileTimeEnumClassName($context, $arg)) {
            return;
        }
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return;
        }

        self::emitJitTypeErrorAndAbort($context, \sprintf(
            '%s(): Argument #1 ($status) must be of type string|int, %s given',
            $function,
            self::jitTypeName($arg->type)
        ));
    }

    private static function emitJitTypeErrorAndAbort(Context $context, string $message): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, $message);
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function jitTypeName(int $type): string
    {
        switch ($type) {
            case JITVariable::TYPE_NATIVE_LONG:
                return 'int';
            case JITVariable::TYPE_NATIVE_DOUBLE:
                return 'float';
            case JITVariable::TYPE_HASHTABLE:
                return 'array';
            case JITVariable::TYPE_OBJECT:
                return 'object';
            case JITVariable::TYPE_BOOLEAN:
                return 'bool';
            case JITVariable::TYPE_NULL:
                return 'null';
            case JITVariable::TYPE_STRING:
                return 'string';
            default:
                return 'mixed';
        }
    }
}
