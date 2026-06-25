<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\ScriptExit;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitOperandTypeLabel;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * exit() — PHP 8.4 proper function form (paren calls only; bare exit; stays a construct).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/basic_functions.c PHP_FUNCTION(exit)
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
        $args = $frame->calledArgs;
        $status = null;
        if (\count($args) > 0) {
            self::validateStatusArg($args[0], $frame, $function);
            $status = $args[0];
        }
        $message = \count($args) > 1 ? $args[1] : null;
        $userFrame = $frame->parent ?? $frame;
        VmExit::terminate($status, $userFrame, $message);
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
        if (Variable::TYPE_OBJECT === $v->type && EnumCaseSupport::isEnumCase($v->toObject())) {
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
        if ($context->callerStrictTypes && isset($args[0])) {
            self::jitRequireStringOrIntStatus($context, $args[0], $function);
        }

        if (\count($args) > 1) {
            ScriptExit::emitWithMessage($context, $args[0], $args[1]);
        } elseif (isset($args[0])) {
            ScriptExit::emit($context, $args[0]);
        } else {
            $null = new JITVariable(
                $context,
                JITVariable::TYPE_NULL,
                JITVariable::KIND_VALUE,
                $context->getTypeFromString('__value__*')->constNull()
            );
            $null->isNullConstant = true;
            ScriptExit::emit($context, $null);
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
