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
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * ReflectionClass::setStaticPropertyValue() — JIT/AOT (#34130, ext/reflection/php_reflection.c).
 *
 * Thin AOT previously had no proxy; ExternalMethod was a silent no-op. Literal
 * property names store via {@see ObjectStaticPropertyLlvm::store} (peer
 * getStaticPropertyValue #34125).
 *
 * php-src: zim_ReflectionClass_setStaticPropertyValue
 */
final class ReflectionClassSetStaticPropertyValue implements Call
{
    private static int $blockSeq = 0;

    public function call(Context $context, Variable ...$args): Value
    {
        $userArgCount = \count($args) - 1;
        if (2 !== $userArgCount) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                sprintf(
                    'ReflectionClass::setStaticPropertyValue() expects exactly 2 arguments, %d given',
                    $userArgCount
                )
            );
            $unreachable = BasicBlockHelper::append(
                $context,
                'refl_class_setstaticpropertyvalue_argc_unreach'
            );
            $context->builder->positionAtEnd($unreachable);

            return self::nullResult($context);
        }

        $propLit = JitStringArg::compileTimeLiteral($args[1]);
        if (null === $propLit) {
            throw new \LogicException(
                'ReflectionClass::setStaticPropertyValue() name must be a string literal in this compiler build'
            );
        }

        $classIdVal = ReflectionClassNewLazyProxy::loadClassIdFromLazyFactoryArg(
            $context,
            $args[0]
        );

        return $this->dispatchByClassId($context, $classIdVal, $propLit, $args[2]);
    }

    private function dispatchByClassId(
        Context $context,
        Value $classId,
        string $propName,
        Variable $value
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
            $cases[] = ['id' => $id, 'has' => null !== $entry];
        }

        $tag = 'sspv'.(string) ++self::$blockSeq;
        $merge = BasicBlockHelper::append($context, 'refl_sspv_merge_'.$tag);
        $undef = BasicBlockHelper::append($context, 'refl_sspv_undef_'.$tag);

        if ([] === $cases) {
            $this->emitMissing($context, $propName, '?');
            $context->builder->branch($merge);
            $context->builder->positionAtEnd($merge);

            return self::nullResult($context);
        }

        $n = \count($cases);
        $checkBlocks = [];
        for ($i = 0; $i < $n; ++$i) {
            $checkBlocks[$i] = 0 === $i
                ? $context->builder->getInsertBlock()
                : BasicBlockHelper::append($context, 'refl_sspv_check_'.$tag.'_'.$i);
        }

        foreach ($cases as $i => $case) {
            $context->builder->positionAtEnd($checkBlocks[$i]);
            $expected = $context->constantFromInteger($case['id'], 'int64');
            $isMatch = $context->builder->icmp(Builder::INT_EQ, $classId, $expected);
            $onMatch = BasicBlockHelper::append($context, 'refl_sspv_match_'.$tag.'_'.$i);
            $onMiss = ($i < $n - 1) ? $checkBlocks[$i + 1] : $undef;
            $context->builder->branchIf($isMatch, $onMatch, $onMiss);

            $context->builder->positionAtEnd($onMatch);
            $display = $object->classNameForId($case['id']);
            if ($case['has']) {
                $entry = $object->staticPropertyGlobalEntry($case['id'], $propName);
                ObjectStaticPropertyLlvm::store(
                    $object,
                    $entry['global'],
                    $value,
                    (int) $entry['type'],
                    $entry['initGlobal'] ?? null
                );
            } else {
                $this->emitMissing($context, $propName, $display);
            }
            $after = $context->builder->getInsertBlock();
            if (null !== $after && null === $after->getTerminator()) {
                $context->builder->branch($merge);
            }
        }

        $context->builder->positionAtEnd($undef);
        $this->emitMissing($context, $propName, '?');
        $afterUndef = $context->builder->getInsertBlock();
        if (null !== $afterUndef && null === $afterUndef->getTerminator()) {
            $context->builder->branch($merge);
        }

        $context->builder->positionAtEnd($merge);

        return self::nullResult($context);
    }

    private function emitMissing(Context $context, string $propName, string $classDisplay): void
    {
        // php-src: ReflectionException "Class X does not have a property named Y"
        ErrorRaise::ensureLinked($context);
        ErrorRaise::emitRaise(
            $context,
            'Class '.$classDisplay.' does not have a property named '.$propName
        );
    }

    private static function nullResult(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return JitValueBox::pointer($context, $slot);
    }
}
