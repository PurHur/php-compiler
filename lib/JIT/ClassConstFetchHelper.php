<?php

declare(strict_types=1);

/**
 * LLVM lowering for dynamic class constant fetch `Class::{$name}` (issue #3150).
 *
 * php-src: {@see https://github.com/php/php-src/blob/master/Zend/zend_compile.c}
 * runtime lookup by name in {@see https://github.com/php/php-src/blob/master/Zend/zend_execute.c}
 */

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\ReadonlyRaise;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCfg\Operand;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class ClassConstFetchHelper
{
    public static function fetchDynamic(
        Object_ $objectType,
        int $classId,
        Variable $nameVar,
        Operand $classOp
    ): Variable {
        $literal = JitStringArg::compileTimeLiteral($nameVar);
        if (null !== $literal) {
            if ('class' === strtolower($literal)) {
                return self::classPseudoConst($objectType, $classId);
            }

            return $objectType->classConstFetch($classId, $literal);
        }

        $context = $objectType->jitContext();
        self::ensureStrCaseCmp($context);
        ReadonlyRaise::ensureLinked($context);

        $nativeName = JitStringArg::lower($context, $nameVar, 'class constant name');
        $resultSlot = JitValueBox::alloc($context);
        $fn = BasicBlockHelper::parentFunction($context);
        $entry = $context->builder->getInsertBlock();
        $merge = $fn->appendBasicBlock('class_const_dyn_merge');
        $fail = $fn->appendBasicBlock('class_const_dyn_fail');

        $classPseudo = $context->builder->load($context->constantStringFromString('class'));
        $context->builder->positionAtEnd($entry);
        $isClass = $context->builder->call(
            $context->lookupFunction('strcasecmp'),
            self::stringDataPtr($context, $nativeName),
            self::stringDataPtr($context, $classPseudo)
        );
        $i32 = $context->getTypeFromString('int32');
        $classMatch = $fn->appendBasicBlock('class_const_dyn_class');
        $constChain = $fn->appendBasicBlock('class_const_dyn_chain');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $isClass, $i32->constInt(0, false)),
            $classMatch,
            $constChain
        );

        $context->builder->positionAtEnd($classMatch);
        $className = $objectType->classNameForId($classId);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $resultSlot),
            $context->builder->load($context->constantStringFromString($className))
        );
        $context->builder->branch($merge);

        $constants = $objectType->classConstantsForId($classId);
        $checkBlock = $constChain;
        $n = count($constants);
        $context->builder->positionAtEnd($constChain);
        if (0 === $n) {
            $context->builder->branch($fail);
        } else {
            foreach ($constants as $i => [$constKey, $entry]) {
                $nextCheck = ($i < $n - 1)
                    ? $fn->appendBasicBlock('class_const_dyn_try_'.$constKey)
                    : $fail;
                $matchBlock = $fn->appendBasicBlock('class_const_dyn_match_'.$constKey);
                $context->builder->positionAtEnd($checkBlock);
                $keyGlobal = $context->builder->load($context->constantStringFromString($constKey));
                $cmp = $context->builder->call(
                    $context->lookupFunction('strcasecmp'),
                    self::stringDataPtr($context, $nativeName),
                    self::stringDataPtr($context, $keyGlobal)
                );
                $isMatch = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
                $context->builder->branchIf($isMatch, $matchBlock, $nextCheck);

                $context->builder->positionAtEnd($matchBlock);
                self::writeConstEntry($context, $resultSlot, $entry);
                $context->builder->branch($merge);
                $checkBlock = $nextCheck;
            }
        }

        $context->builder->positionAtEnd($fail);
        $displayClass = self::displayClassName($objectType, $classId, $classOp);
        $message = "Undefined class constant {$displayClass}::*";
        $context->builder->call(
            $context->lookupFunction('__compiler_jit_raise_logic_exception'),
            self::messageDataPtr($context, $message),
            $context->constantFromInteger(strlen($message), 'size_t')
        );
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($merge);

        return new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $resultSlot
        );
    }

    private static function classPseudoConst(Object_ $objectType, int $classId): Variable
    {
        $context = $objectType->jitContext();
        $lit = new Operand\Literal($objectType->classNameForId($classId));
        $lit->type = \PHPTypes\Type::string();

        return Variable::fromLiteral($context, $lit);
    }

    /**
     * @param array{type: int, value: int|float|bool|string|null} $entry
     */
    private static function writeConstEntry(Context $context, Value $slot, array $entry): void
    {
        switch ($entry['type']) {
            case Variable::TYPE_NATIVE_LONG:
                JitValueBox::writeLong(
                    $context,
                    $slot,
                    $context->constantFromInteger((int) $entry['value'])
                );
                break;
            case Variable::TYPE_NATIVE_DOUBLE:
                $context->builder->call(
                    $context->lookupFunction('__value__writeDouble'),
                    JitValueBox::pointer($context, $slot),
                    $context->getTypeFromString('double')->constReal((float) $entry['value'])
                );
                break;
            case Variable::TYPE_NATIVE_BOOL:
                $context->builder->call(
                    $context->lookupFunction('__value__writeBool'),
                    JitValueBox::pointer($context, $slot),
                    $context->constantFromBool((bool) $entry['value'])
                );
                break;
            case Variable::TYPE_STRING:
                $context->builder->call(
                    $context->lookupFunction('__value__writeString'),
                    JitValueBox::pointer($context, $slot),
                    $context->builder->load($context->constantStringFromString((string) $entry['value']))
                );
                break;
            case Variable::TYPE_NULL:
                $context->builder->call(
                    $context->lookupFunction('__value__writeNull'),
                    JitValueBox::pointer($context, $slot)
                );
                break;
            default:
                throw new \LogicException('Unsupported class constant type for dynamic JIT fetch');
        }
    }

    private static function displayClassName(Object_ $objectType, int $classId, Operand $classOp): string
    {
        if ($classOp instanceof Operand\Literal) {
            return $classOp->value;
        }

        return $objectType->classNameForId($classId);
    }

    private static function ensureStrCaseCmp(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($i32, false, $i8p, $i8p);
        try {
            $context->lookupFunction('strcasecmp');
        } catch (\Throwable $e) {
            $fn = $context->module->addFunction('strcasecmp', $ft);
            $context->registerFunction('strcasecmp', $fn);
        }
    }

    private static function stringDataPtr(Context $context, Value $strPtr): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->structGep($strPtr, $map['value']);
    }

    private static function messageDataPtr(Context $context, string $message): Value
    {
        $strPtr = $context->builder->load($context->constantStringFromString($message));
        $strMap = $context->structFieldMap['__string__'];

        return $context->builder->pointerCast(
            $context->builder->structGep($strPtr, $strMap['value']),
            $context->getTypeFromString('int8*')
        );
    }
}
