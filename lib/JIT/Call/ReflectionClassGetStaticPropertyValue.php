<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Builtin\Type\ObjectStaticPropertyLlvm;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * ReflectionClass::getStaticPropertyValue() — JIT/AOT (#34125, ext/reflection/php_reflection.c).
 *
 * Thin AOT previously had no proxy; ExternalMethod returned NULL. Literal property
 * names load live statics via {@see ObjectStaticPropertyLlvm::fetch} (peer getConstant
 * #34093 / getStaticProperties #34118). Optional default returned when undeclared.
 *
 * php-src: zim_ReflectionClass_getStaticPropertyValue
 */
final class ReflectionClassGetStaticPropertyValue implements Call
{
    private static int $blockSeq = 0;

    public function call(Context $context, Variable ...$args): Value
    {
        $userArgCount = \count($args) - 1;
        if ($userArgCount < 1 || $userArgCount > 2) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                sprintf(
                    'ReflectionClass::getStaticPropertyValue() expects at least 1 argument, %d given',
                    $userArgCount
                )
            );
            $unreachable = BasicBlockHelper::append(
                $context,
                'refl_class_getstaticpropertyvalue_argc_unreach'
            );
            $context->builder->positionAtEnd($unreachable);

            return JitValueBox::alloc($context);
        }

        $propLit = JitStringArg::compileTimeLiteral($args[1]);
        if (null === $propLit) {
            throw new \LogicException(
                'ReflectionClass::getStaticPropertyValue() name must be a string literal in this compiler build'
            );
        }

        $classIdVal = ReflectionClassNewLazyProxy::loadClassIdFromLazyFactoryArg(
            $context,
            $args[0]
        );
        $default = 2 === $userArgCount ? $args[2] : null;

        return $this->dispatchByClassId($context, $classIdVal, $propLit, $default);
    }

    private function dispatchByClassId(
        Context $context,
        Value $classId,
        string $propName,
        ?Variable $default
    ): Value {
        $object = $context->type->object;
        /** @var list<array{id: int, has: bool}> $cases */
        $cases = [];
        foreach ($object->allClassNamesById() as $id => $name) {
            if (!$object->hasUserDeclaredClass($name)) {
                continue;
            }
            $id = (int) $id;
            $entry = $object->staticPropertyGlobalEntry($id, $propName);
            // Parent-private statics are absent from the child table (Zend ReflectionException).
            $cases[] = ['id' => $id, 'has' => null !== $entry];
        }

        $tag = 'gspv'.(string) ++self::$blockSeq;
        $resultSlot = BasicBlockHelper::entryAlloca(
            $context,
            $context->getTypeFromString('__value__*')
        );
        $merge = BasicBlockHelper::append($context, 'refl_gspv_merge_'.$tag);
        $undef = BasicBlockHelper::append($context, 'refl_gspv_undef_'.$tag);

        if ([] === $cases) {
            $context->builder->positionAtEnd($context->builder->getInsertBlock());
            $this->emitMissing($context, $resultSlot, $propName, '?', $default);
            $context->builder->branch($merge);
            $context->builder->positionAtEnd($merge);

            return $context->builder->load($resultSlot);
        }

        $n = \count($cases);
        $checkBlocks = [];
        for ($i = 0; $i < $n; ++$i) {
            $checkBlocks[$i] = 0 === $i
                ? $context->builder->getInsertBlock()
                : BasicBlockHelper::append($context, 'refl_gspv_check_'.$tag.'_'.$i);
        }

        foreach ($cases as $i => $case) {
            $context->builder->positionAtEnd($checkBlocks[$i]);
            $expected = $context->constantFromInteger($case['id'], 'int64');
            $isMatch = $context->builder->icmp(Builder::INT_EQ, $classId, $expected);
            $onMatch = BasicBlockHelper::append($context, 'refl_gspv_match_'.$tag.'_'.$i);
            $onMiss = ($i < $n - 1) ? $checkBlocks[$i + 1] : $undef;
            $context->builder->branchIf($isMatch, $onMatch, $onMiss);

            $context->builder->positionAtEnd($onMatch);
            $display = $object->classNameForId($case['id']);
            if ($case['has']) {
                $fetched = ObjectStaticPropertyLlvm::fetch($object, $case['id'], $propName);
                $tmp = JitValueBox::alloc($context);
                JitValueBox::assignToPointer(
                    $context,
                    JitValueBox::pointer($context, $tmp),
                    $fetched
                );
                $context->builder->store(
                    JitValueBox::coerceToValuePtrForStore($context, $tmp),
                    $resultSlot
                );
            } else {
                $this->emitMissing($context, $resultSlot, $propName, $display, $default);
            }
            $after = $context->builder->getInsertBlock();
            if (null !== $after && null === $after->getTerminator()) {
                $context->builder->branch($merge);
            }
        }

        $context->builder->positionAtEnd($undef);
        $this->emitMissing($context, $resultSlot, $propName, '?', $default);
        $afterUndef = $context->builder->getInsertBlock();
        if (null !== $afterUndef && null === $afterUndef->getTerminator()) {
            $context->builder->branch($merge);
        }

        $context->builder->positionAtEnd($merge);

        return $context->builder->load($resultSlot);
    }

    private function emitMissing(
        Context $context,
        Value $resultSlot,
        string $propName,
        string $classDisplay,
        ?Variable $default
    ): void {
        if (null !== $default) {
            $tmp = JitValueBox::alloc($context);
            JitValueBox::assignToPointer(
                $context,
                JitValueBox::pointer($context, $tmp),
                $default
            );
            $context->builder->store(
                JitValueBox::coerceToValuePtrForStore($context, $tmp),
                $resultSlot
            );

            return;
        }
        // php-src: ReflectionException "Property Class::$name does not exist"
        ErrorRaise::ensureLinked($context);
        ErrorRaise::emitRaise(
            $context,
            'Property '.$classDisplay.'::$'.$propName.' does not exist'
        );
        $nullBox = JitValueBox::alloc($context);
        $context->builder->store(
            JitValueBox::coerceToValuePtrForStore($context, $nullBox),
            $resultSlot
        );
    }
}
