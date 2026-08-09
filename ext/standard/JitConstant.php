<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DefineRuntime;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Builtin\GlobalIntrospectionNameRuntime;
use PHPCompiler\JIT\ClassConstFetchHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VMVariable;
use PHPCfg\Operand\Literal;
use PHPCfg\Type;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for constant() (issue #3813, runtime names #4435). */
final class JitConstant
{
    public static function invoke(Context $context, JITVariable $nameArg): Value
    {
        if (JITVariable::TYPE_STRING !== $nameArg->type) {
            throw new \LogicException('constant() name type check should run in constant_::call');
        }
        if (null !== $nameArg->compileTimeString) {
            return self::invokeLiteral($context, $nameArg->compileTimeString);
        }

        return self::invokeRuntime($context, $context->helper->loadValue($nameArg));
    }

    private static function invokeLiteral(Context $context, string $name): Value
    {
        if (null === $context->runtime->vmContext) {
            throw new \LogicException('constant() requires VM context');
        }
        $name = VmReflection::normalizeGlobalIntrospectionName($name);
        $callerLc = '' !== ($context->scope->className ?? '')
            ? strtolower(ltrim($context->scope->className, '\\'))
            : null;
        $calledLc = '' !== ($context->scope->calledClassName ?? '')
            ? strtolower(ltrim($context->scope->calledClassName, '\\'))
            : $callerLc;

        // constant('static::X') — mirror CLASS_CONST_FETCH for literal static:: (#29455 / #19614).
        $staticPtr = self::tryInvokeLiteralStaticClassConst($context, $name);
        if (null !== $staticPtr) {
            return $staticPtr;
        }

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        try {
            $phpVar = VmConstants::constantLookup(
                $context->runtime->vmContext,
                $name,
                $callerLc,
                $calledLc
            );
        } catch (\Error $e) {
            // Visibility / relative-scope Error must be runtime so try/catch around constant() works (#29130).
            ErrorRaise::registerDeclarations($context);
            ErrorRaise::ensureLinked($context);
            ErrorRaise::emitRaise($context, $e->getMessage());
            $context->builder->call($context->lookupFunction('abort'));

            return $ptr;
        }
        if (null !== $phpVar) {
            self::writeVmVariable($context, $slot, $phpVar->resolveIndirect());

            return $ptr;
        }
        if (str_contains($name, '::')) {
            throw new \LogicException('Undefined constant '.$name);
        }
        throw new \LogicException('Undefined constant "'.$name.'"');
    }

    /**
     * Lower constant('static::CONST') like opcode static::CONST (LSB) (#29455).
     */
    private static function tryInvokeLiteralStaticClassConst(Context $context, string $name): ?Value
    {
        $pos = strrpos($name, '::');
        if (false === $pos) {
            return null;
        }
        $classPart = substr($name, 0, $pos);
        $constPart = substr($name, $pos + 2);
        if ('' === $constPart || 'static' !== strtolower(ltrim($classPart, '\\'))) {
            return null;
        }
        $block = $context->jitCurrentBlock ?? $context->jitEnclosingBlock;
        $objectType = $context->type->object ?? null;
        if (null === $block || null === $objectType) {
            return null;
        }
        $classOp = new Literal('static');
        $classOp->type = Type::string();
        $classVar = JITVariable::fromLiteral($context, $classOp);
        $fetched = ClassConstFetchHelper::fetchLiteralConstWithRuntimeClass(
            $objectType,
            $block,
            $classVar,
            $classOp,
            $constPart,
            null
        );

        return JitValueBox::pointer($context, $fetched->value);
    }

    private static function invokeRuntime(Context $context, Value $nameStr): Value
    {
        $nameStr = GlobalIntrospectionNameRuntime::normalizeString($context, $nameStr);
        $ht = DefineRuntime::loadTable($context);
        $valPtr = $context->builder->call(
            $context->lookupFunction('__hashtable__peekStringKeyValue'),
            $ht,
            $nameStr
        );
        $valTy = $context->getTypeFromString('__value__*');
        $isMissing = $context->builder->icmp(Builder::INT_EQ, $valPtr, $valTy->constNull());
        $tag = 'const'.spl_object_id($context);
        $ok = BasicBlockHelper::append($context, 'jit_const_ok_'.$tag);
        $bad = BasicBlockHelper::append($context, 'jit_const_bad_'.$tag);
        $context->builder->branchIf($isMissing, $bad, $ok);

        $context->builder->positionAtEnd($bad);
        ErrorRaise::registerDeclarations($context);
        ErrorRaise::ensureLinked($context);
        ErrorRaise::emitRaise($context, 'Undefined constant');
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->positionAtEnd($ok);

        return $valPtr;
    }

    private static function writeVmVariable(Context $context, Value $slot, VMVariable $value): void
    {
        $ptr = JitValueBox::pointer($context, $slot);
        switch ($value->type) {
            case VMVariable::TYPE_INTEGER:
                JitValueBox::writeLong(
                    $context,
                    $slot,
                    $context->getTypeFromString('int64')->constInt($value->toInt(), false)
                );

                return;
            case VMVariable::TYPE_BOOLEAN:
                JitValueBox::writeBool(
                    $context,
                    $slot,
                    $context->constantFromBool($value->toBool())
                );

                return;
            case VMVariable::TYPE_FLOAT:
                $context->builder->call(
                    $context->lookupFunction('__value__writeDouble'),
                    $ptr,
                    $context->constantFromFloat($value->toFloat())
                );

                return;
            case VMVariable::TYPE_STRING:
                $owned = $context->builder->call(
                    $context->lookupFunction('__string__separate'),
                    $context->builder->load($context->constantStringFromString($value->toString()))
                );
                $context->builder->call(
                    $context->lookupFunction('__value__writeString'),
                    $ptr,
                    $owned
                );

                return;
            case VMVariable::TYPE_NULL:
                $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);

                return;
            default:
                throw new \LogicException(
                    'constant() unsupported constant type: '.VMVariable::getStringType($value->type)
                );
        }
    }
}
