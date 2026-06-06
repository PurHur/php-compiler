<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for readonly() — set __object__.dynamic_readonly (#6485). */
final class JitReadonly
{
    private const TYPE_ERROR = 'readonly(): Argument #1 ($object) must be of type object, %s given';

    private const ALREADY_READONLY = 'Object is already readonly';

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (1 !== $argc) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'readonly() expects exactly 1 argument, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        $arg = $args[0];
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            self::markObject($context, $context->helper->loadValue($arg));

            return $context->context->voidType()->constNull();
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            self::markBoxed($context, $arg);

            return $context->context->voidType()->constNull();
        }

        self::emitTypeErrorAndAbort($context, self::scalarTypeError($arg->type));

        return $context->context->voidType()->constNull();
    }

    private static function markBoxed(Context $context, JITVariable $arg): void
    {
        $loaded = JitValueBox::valuePtrFromVariable($context, $arg);
        $typeField = $context->structFieldMap['__value__']['type'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($loaded, $typeField)
        );
        $i8 = $context->getTypeFromString('int8');
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_OBJECT, false)
        );
        $okBlock = BasicBlockHelper::append($context, 'readonly_ok');
        $errBlock = BasicBlockHelper::append($context, 'readonly_err');
        $context->builder->branchIf($isObject, $okBlock, $errBlock);

        $context->builder->positionAtEnd($errBlock);
        self::emitTypeErrorAndAbort($context, \sprintf(self::TYPE_ERROR, 'mixed'));

        $context->builder->positionAtEnd($okBlock);
        $obj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $loaded
        );
        self::markObject($context, $obj);
    }

    private static function markObject(Context $context, Value $obj): void
    {
        ErrorRaise::ensureLinked($context);
        $map = $context->structFieldMap['__object__'];
        $flagPtr = $context->builder->structGep($obj, $map['dynamic_readonly']);
        $i8 = $context->getTypeFromString('int8');
        $current = $context->builder->load($flagPtr);
        $already = $context->builder->icmp(
            Builder::INT_NE,
            $current,
            $i8->constInt(0, false)
        );
        $fn = BasicBlockHelper::parentFunction($context);
        $failBlock = $fn->appendBasicBlock('readonly_already');
        $setBlock = $fn->appendBasicBlock('readonly_set');
        $done = $fn->appendBasicBlock('readonly_done');
        $entry = $context->builder->getInsertBlock();
        $context->builder->branchIf($already, $failBlock, $setBlock);

        $context->builder->positionAtEnd($failBlock);
        ErrorRaise::emitRaise($context, self::ALREADY_READONLY);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($setBlock);
        $context->builder->store($i8->constInt(1, false), $flagPtr);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    private static function emitTypeErrorAndAbort(Context $context, string $message): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, $message);
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function scalarTypeError(int $jitType): string
    {
        switch ($jitType) {
            case JITVariable::TYPE_INTEGER:
                return \sprintf(self::TYPE_ERROR, 'int');
            case JITVariable::TYPE_FLOAT:
                return \sprintf(self::TYPE_ERROR, 'float');
            case JITVariable::TYPE_BOOLEAN:
                return \sprintf(self::TYPE_ERROR, 'bool');
            case JITVariable::TYPE_STRING:
                return \sprintf(self::TYPE_ERROR, 'string');
            case JITVariable::TYPE_NULL:
                return \sprintf(self::TYPE_ERROR, 'null');
            case JITVariable::TYPE_ARRAY:
                return \sprintf(self::TYPE_ERROR, 'array');
            default:
                return \sprintf(self::TYPE_ERROR, 'mixed');
        }
    }
}
