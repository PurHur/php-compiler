<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\JIT\Call\Native;
use PHPCompiler\OpCode;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * MCJIT lowering for user generators (issue #167, #3074).
 *
 * Switch-on-resume-ip for generator bodies; foreach over Generator uses this helper.
 * php-src: Zend/zend_generators.c.
 */
final class GeneratorHelper
{
    public const TARGET_PROPERTY = '__generator_resume';

    private static bool $typesRegistered = false;

    public static function ensureTypes(Context $context): void
    {
        if (self::$typesRegistered) {
            return;
        }
        self::$typesRegistered = true;
        $struct = $context->context->namedStructType('__generator_state__');
        $context->registerType('__generator_state__', $struct);
        $context->registerType('__generator_state__*', $struct->pointerType(0));
        $struct->setBody(
            false,
            $context->getTypeFromString('size_t'),
            $context->getTypeFromString('size_t'),
            $context->getTypeFromString('int1'),
            $context->getTypeFromString('int1'),
            $context->getTypeFromString('__value__'),
            $context->getTypeFromString('__value__'),
        );
        $context->structFieldMap['__generator_state__'] = [
            'resume_ip' => 0,
            'auto_key' => 1,
            'has_current' => 2,
            'done' => 3,
            'current_key' => 4,
            'current_value' => 5,
        ];
    }

    public static function registerCreator(Context $context, string $funcLc, string $resumeInternalName): void
    {
        $context->generatorCreators[strtolower($funcLc)] = $resumeInternalName;
    }

    public static function creatorResumeName(Context $context, string $funcLc): ?string
    {
        $lc = strtolower($funcLc);
        if (isset($context->generatorCreators[$lc])) {
            return $context->generatorCreators[$lc];
        }
        if (preg_match('/^(.+)\\\\([^\\\\]+)$/', $lc, $m)) {
            $short = $m[2];
            if (isset($context->generatorCreators[$short])) {
                return $context->generatorCreators[$short];
            }
        }

        return null;
    }

    public static function isGeneratorVariable(Variable $var): bool
    {
        return null !== $var->generatorStatePtr;
    }

    /**
     * @return list<OpCode>
     */
    public static function linearYieldOpcodes(Block $block): array
    {
        $yields = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_YIELD === $op->type) {
                $yields[] = $op;
            } elseif (OpCode::TYPE_YIELD_FROM === $op->type) {
                throw new \LogicException('yield from is not supported in JIT generators yet (issue #3074)');
            } elseif (OpCode::TYPE_RETURN === $op->type || OpCode::TYPE_RETURN_VOID === $op->type) {
                break;
            } elseif (
                OpCode::TYPE_TRY === $op->type
                || OpCode::TYPE_CATCH === $op->type
                || OpCode::TYPE_FINALLY === $op->type
                || OpCode::TYPE_THROW === $op->type
            ) {
                throw new \LogicException('try/catch in generator JIT is not supported yet (issue #3074)');
            }
        }

        return $yields;
    }

    public static function compileResumeFunction(
        \PHPCompiler\JIT $jit,
        string $internalName,
        Block $block,
        string $logicalName
    ): Value {
        $context = $jit->context;
        self::ensureTypes($context);
        $resumeName = $internalName.'__resume';
        $lc = strtolower($resumeName);
        self::registerCreator($context, $logicalName, $resumeName);
        if (isset($context->functions[$lc])) {
            return $context->functions[$lc];
        }

        $statePtrTy = $context->getTypeFromString('__generator_state__*');
        $i64 = $context->getTypeFromString('int64');
        $func = $context->module->addFunction(
            self::llvmInternalName($resumeName),
            $context->context->functionType($i64, false, $statePtrTy)
        );
        $stateParam = $func->getParam(0);
        $savedBuilder = $context->builder;
        $context->builder = $context->context->builderCreate();
        $context->compilingGeneratorResume = true;
        $context->generatorStateParam = $stateParam;

        $entry = $func->appendBasicBlock('gen_entry');
        $context->builder->positionAtEnd($entry);
        $yields = self::linearYieldOpcodes($block);
        $n = count($yields);
        $map = $context->structFieldMap['__generator_state__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i1 = $context->getTypeFromString('int1');
        $zero = $sizeT->constInt(0, false);

        $context->builder->store($zero, $context->builder->structGep($stateParam, $map['auto_key']));
        $resumeIp = $context->builder->load($context->builder->structGep($stateParam, $map['resume_ip']));
        $doneBb = $func->appendBasicBlock('gen_done');
        $switchInst = $context->builder->branchSwitch($resumeIp, $doneBb, $n);

        for ($i = 0; $i < $n; ++$i) {
            $caseBb = $func->appendBasicBlock('gen_case_'.$i);
            $switchInst->addCase($sizeT->constInt($i, false), $caseBb);
            $context->builder->positionAtEnd($caseBb);
            self::emitYieldPoint($jit, $block, $yields[$i], $stateParam, $i + 1);
        }

        $context->builder->positionAtEnd($doneBb);
        $context->builder->store($i1->constInt(1, false), $context->builder->structGep($stateParam, $map['done']));
        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($stateParam, $map['has_current']));
        $context->builder->returnValue($i64->constInt(0, false));

        $context->builder->clearInsertionPosition();
        $context->builder = $savedBuilder;
        $context->compilingGeneratorResume = false;
        $context->generatorStateParam = null;

        $context->functions[$lc] = $func;
        $context->functionReturnType[$lc] = 'int64';
        $context->functionProxies[$lc] = new Native($func, $resumeName, [$statePtrTy], []);

        return $func;
    }

    private static function emitYieldPoint(
        \PHPCompiler\JIT $jit,
        Block $block,
        OpCode $op,
        Value $stateParam,
        int $nextResumeIp
    ): void {
        $context = $jit->context;
        $map = $context->structFieldMap['__generator_state__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i1 = $context->getTypeFromString('int1');
        $i64 = $context->getTypeFromString('int64');

        $valueOp = null !== $op->arg2 ? $block->getOperand($op->arg2) : null;
        $keyOp = null !== $op->arg3 ? $block->getOperand($op->arg3) : null;
        $valField = $context->builder->structGep($stateParam, $map['current_value']);
        $keyField = $context->builder->structGep($stateParam, $map['current_key']);

        if (null !== $valueOp) {
            $valVar = $context->getVariableFromOp($valueOp);
            $jit->assignValueToGeneratorField($valField, $valVar, $valueOp);
        } else {
            $context->builder->call(
                $context->lookupFunction('__value__writeNull'),
                JitValueBox::pointer($context, $valField)
            );
        }

        if (null !== $keyOp) {
            $keyVar = $context->getVariableFromOp($keyOp);
            $jit->assignValueToGeneratorField($keyField, $keyVar, $keyOp);
        } else {
            $autoKey = $context->builder->load($context->builder->structGep($stateParam, $map['auto_key']));
            $context->builder->call(
                $context->lookupFunction('__value__writeLong'),
                JitValueBox::pointer($context, $keyField),
                $context->builder->truncOrBitCast($autoKey, $context->getTypeFromString('int64'))
            );
            $context->builder->store(
                $context->builder->addNoSignedWrap($autoKey, $sizeT->constInt(1, false)),
                $context->builder->structGep($stateParam, $map['auto_key'])
            );
        }

        $context->builder->store(
            $i1->constInt(1, false),
            $context->builder->structGep($stateParam, $map['has_current'])
        );
        $context->builder->store(
            $sizeT->constInt($nextResumeIp, false),
            $context->builder->structGep($stateParam, $map['resume_ip'])
        );
        $context->builder->returnValue($i64->constInt(1, false));
    }

    public static function emitCreateFromCall(
        \PHPCompiler\JIT $jit,
        string $resumeInternalName
    ): Variable {
        $context = $jit->context;
        self::ensureTypes($context);
        $stateTy = $context->getTypeFromString('__generator_state__');
        $statePtr = $context->memory->malloc($stateTy);
        $map = $context->structFieldMap['__generator_state__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i1 = $context->getTypeFromString('int1');
        $zero = $sizeT->constInt(0, false);
        $context->builder->store($zero, $context->builder->structGep($statePtr, $map['resume_ip']));
        $context->builder->store($zero, $context->builder->structGep($statePtr, $map['auto_key']));
        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($statePtr, $map['has_current']));
        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($statePtr, $map['done']));
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $context->builder->structGep($statePtr, $map['current_key']))
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $context->builder->structGep($statePtr, $map['current_value']))
        );

        $classId = $context->type->object->lookup('Generator');
        $obj = $context->type->object->allocate($classId);
        $context->type->object->markObjectConstructed($obj);
        self::storeResumeName($context, $obj, $resumeInternalName);

        $var = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $obj);
        $var->generatorStatePtr = $statePtr;
        $var->generatorResumeName = $resumeInternalName;

        return $var;
    }

    private static function storeResumeName(Context $context, Value $obj, string $resumeName): void
    {
        $targetStr = new Variable(
            $context,
            Variable::TYPE_STRING,
            Variable::KIND_VALUE,
            $context->builder->load($context->constantStringFromString(strtolower($resumeName)))
        );
        $targetStr->addref();
        $context->type->object->storeInstanceProperty(
            $obj,
            'Generator',
            self::TARGET_PROPERTY,
            $targetStr
        );
    }

    public static function compileIterValid(Context $context, Variable $gen): Value
    {
        if (null === $gen->generatorStatePtr || null === $gen->generatorResumeName) {
            throw new \LogicException('foreach requires a Generator value in this compiler build');
        }
        $state = $gen->generatorStatePtr;
        $map = $context->structFieldMap['__generator_state__'];
        $i1 = $context->getTypeFromString('int1');
        $done = $context->builder->load($context->builder->structGep($state, $map['done']));
        $fn = $context->builder->getInsertBlock()->getParent();
        $early = $fn->appendBasicBlock('gen_iter_done');
        $body = $fn->appendBasicBlock('gen_iter_resume');
        $merge = $fn->appendBasicBlock('gen_iter_merge');
        $context->builder->branchIf($done, $early, $body);
        $context->builder->positionAtEnd($early);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($body);
        $resumeFn = $context->functions[strtolower($gen->generatorResumeName)] ?? null;
        if (!$resumeFn instanceof \PHPLLVM\Value\Function_) {
            throw new \LogicException('Generator resume function missing from JIT context');
        }
        $yielded = $context->builder->call($resumeFn, $state);
        $has = $context->builder->icmp(
            Builder::INT_NE,
            $yielded,
            $context->getTypeFromString('int64')->constInt(0, false)
        );
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);
        $phi = $context->builder->phi($i1);
        $phi->addIncoming($i1->constInt(0, false), $early);
        $phi->addIncoming($has, $body);

        return $phi;
    }

    public static function compileIterKey(Context $context, Variable $gen): Variable
    {
        if (null === $gen->generatorStatePtr) {
            throw new \LogicException('Generator iterator key requires generator state');
        }
        $keyField = $context->builder->structGep(
            $gen->generatorStatePtr,
            $context->structFieldMap['__generator_state__']['current_key']
        );
        $slot = JitValueBox::alloc($context);
        JitValueBox::copyFromPointer($context, $slot, $keyField);

        return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $slot);
    }

    public static function compileIterValue(Context $context, Variable $gen): Variable
    {
        if (null === $gen->generatorStatePtr) {
            throw new \LogicException('Generator iterator value requires generator state');
        }
        $valField = $context->builder->structGep(
            $gen->generatorStatePtr,
            $context->structFieldMap['__generator_state__']['current_value']
        );
        $slot = JitValueBox::alloc($context);
        JitValueBox::copyFromPointer($context, $slot, $valField);

        return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $slot);
    }

    public static function compileIterReset(Context $context, Variable $gen): void
    {
        if (null === $gen->generatorStatePtr) {
            return;
        }
        $state = $gen->generatorStatePtr;
        $map = $context->structFieldMap['__generator_state__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i1 = $context->getTypeFromString('int1');
        $zero = $sizeT->constInt(0, false);
        $context->builder->store($zero, $context->builder->structGep($state, $map['resume_ip']));
        $context->builder->store($zero, $context->builder->structGep($state, $map['auto_key']));
        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($state, $map['has_current']));
        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($state, $map['done']));
    }

    private static function llvmInternalName(string $name): string
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9_]/', '_', $name) ?? $name;
        if ('main' === $sanitized || '__init__' === $sanitized || '__shutdown__' === $sanitized) {
            return 'php_user_'.$sanitized;
        }

        return $sanitized;
    }
}
