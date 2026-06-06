<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\ReflectionBuiltinHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * is_a() — extends-chain instance check (php-src ext/standard/class.c, issue #3478).
 */
final class is_a_ extends Internal
{
    private const SUBJECT_TYPE_ERROR =
        'is_a(): Argument #1 ($object_or_class) must be of type object|string, %s given';

    private const SUBJECT_TYPE_ERROR_STRING =
        'is_a(): Argument #1 ($object_or_class) must be of type object|string, string given';

    public function __construct()
    {
        parent::__construct('is_a');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs) && 3 !== \count($frame->calledArgs)) {
            throw new \LogicException('is_a() requires two or three arguments');
        }
        $ctx = VmReflection::requireContext($frame);
        $className = VmReflection::stringArg($frame->calledArgs[1], 'is_a() class name', 1);
        $allowString = false;
        if (3 === \count($frame->calledArgs)) {
            $allowString = $frame->calledArgs[2]->resolveIndirect()->toBool();
        }
        $subject = $frame->calledArgs[0]->resolveIndirect();
        self::requireValidSubject($subject, $allowString);
        $matches = false;
        if (Variable::TYPE_OBJECT === $subject->type || Variable::TYPE_ENUM_CASE === $subject->type) {
            $matches = VmReflection::isInstanceOfObject($ctx, $subject, $className);
        } elseif ($allowString && Variable::TYPE_STRING === $subject->type) {
            $child = VmReflection::resolveClassEntry($ctx, $subject->toString());
            $matches = null !== $child
                && VmReflection::isInstanceOf($ctx, $child, $className);
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($matches);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args) && 3 !== \count($args)) {
            throw new \LogicException('is_a() requires two or three arguments');
        }
        $allowString = $context->constantFromBool(false);
        $allowStringKnownFalse = true;
        if (3 === \count($args)) {
            $allowString = JitBoolArg::lower($context, $args[2], 'is_a() allow_string');
            $allowStringKnownFalse = self::jitAllowStringKnownFalse($context, $args[2]);
        }
        if (!\in_array($args[0]->type, [
            JITVariable::TYPE_OBJECT,
            JITVariable::TYPE_STRING,
            JITVariable::TYPE_VALUE,
        ], true)) {
            self::emitJitTypeErrorAndAbort($context, self::jitTypeErrorMessage($args[0]->type));
        }
        $className = ReflectionBuiltinHelper::requireCompileTimeClassName(
            $context,
            $args[1],
            'is_a() class name'
        );
        if (JITVariable::TYPE_STRING === $args[0]->type) {
            $this->jitString($context, $args[0], 'is_a() subject');
            if ($allowStringKnownFalse) {
                self::emitJitTypeErrorAndAbort($context, self::SUBJECT_TYPE_ERROR_STRING);
            }
            $subjectName = ReflectionBuiltinHelper::requireCompileTimeClassName(
                $context,
                $args[0],
                'is_a() subject class name'
            );
            $match = ReflectionBuiltinHelper::classIsInstanceOfLiteral(
                $context,
                $subjectName,
                $className
            );
            $i1 = $context->getTypeFromString('int1');

            return $context->builder->select(
                $allowString,
                $match,
                $i1->constInt(0, false)
            );
        }

        return $context->helper->loadValue(
            ReflectionBuiltinHelper::emitInstanceOf($context, $args[0], $className)
        );
    }

    private static function requireValidSubject(Variable $subject, bool $allowString): void
    {
        if (Variable::TYPE_OBJECT === $subject->type || Variable::TYPE_ENUM_CASE === $subject->type) {
            return;
        }
        if ($allowString && Variable::TYPE_STRING === $subject->type) {
            return;
        }
        throw new \TypeError(\sprintf(self::SUBJECT_TYPE_ERROR, self::vmTypeName($subject->type)));
    }

    private static function jitAllowStringKnownFalse(Context $context, JITVariable $arg): bool
    {
        if (JITVariable::TYPE_NATIVE_BOOL === $arg->type && JITVariable::KIND_VALUE === $arg->kind) {
            return 0 === (int) $context->llvm->lib->LLVMConstIntGetZExtValue($arg->value->value);
        }

        return false;
    }

    private static function emitJitTypeErrorAndAbort(Context $context, string $message): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, $message);
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function jitTypeErrorMessage(int $type): string
    {
        switch ($type) {
            case JITVariable::TYPE_NATIVE_LONG:
                return \sprintf(self::SUBJECT_TYPE_ERROR, 'int');
            case JITVariable::TYPE_NATIVE_DOUBLE:
                return \sprintf(self::SUBJECT_TYPE_ERROR, 'float');
            case JITVariable::TYPE_NATIVE_BOOL:
                return \sprintf(self::SUBJECT_TYPE_ERROR, 'bool');
            case JITVariable::TYPE_NULL:
                return \sprintf(self::SUBJECT_TYPE_ERROR, 'null');
            default:
                return \sprintf(self::SUBJECT_TYPE_ERROR, 'mixed');
        }
    }

    private static function vmTypeName(int $type): string
    {
        switch ($type) {
            case Variable::TYPE_INTEGER:
                return 'int';
            case Variable::TYPE_FLOAT:
                return 'float';
            case Variable::TYPE_BOOLEAN:
                return 'bool';
            case Variable::TYPE_STRING:
                return 'string';
            case Variable::TYPE_NULL:
                return 'null';
            case Variable::TYPE_ARRAY:
                return 'array';
            case Variable::TYPE_OBJECT:
                return 'object';
            case Variable::TYPE_ENUM_CASE:
                return 'object';
            default:
                return 'mixed';
        }
    }
}
