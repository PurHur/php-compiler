<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\LLVMAbstract\Builder as LLVMBuilderImpl;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;
use llvm\LLVMValueRef_ptr;

/**
 * JIT/AOT spl_autoload_register() stack via SplAutoloadJitHelper PHP (#1776, #2441, #9238).
 *
 * Replaces LLVM module globals (`__phpc_spl_autoload_stack` et al.); SSOT
 * {@see \PHPCompiler\ext\standard\SplAutoloadJitHelper}.
 * php-src: Zend/zend_autoload.c — spl_autoload_register / spl_autoload_call
 */
final class SplAutoloadOutput
{
    private const HELPER_PATH = '/ext/standard/SplAutoloadJitHelper.php';

    private const REGISTER_HELPER = 'PHPCompiler\\ext\\standard\\SplAutoloadJitHelper::registerApply';

    private const UNREGISTER_HELPER = 'PHPCompiler\\ext\\standard\\SplAutoloadJitHelper::unregisterApply';

    private const DEPTH_HELPER = 'PHPCompiler\\ext\\standard\\SplAutoloadJitHelper::depth';

    private const FN_OPAQUE_AT_HELPER = 'PHPCompiler\\ext\\standard\\SplAutoloadJitHelper::fnOpaqueAt';

    private const META_OPAQUE_AT_HELPER = 'PHPCompiler\\ext\\standard\\SplAutoloadJitHelper::metaOpaqueAt';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::REGISTER_HELPER,
        self::UNREGISTER_HELPER,
        self::DEPTH_HELPER,
        self::FN_OPAQUE_AT_HELPER,
        self::META_OPAQUE_AT_HELPER,
    ];

    /** @var list<string> */
    private const RUNTIME_FNS = [
        '__phpc_spl_autoload_register_apply',
        '__phpc_spl_autoload_unregister_apply',
        '__phpc_spl_autoload_dispatch',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $registerProbe = $context->module->getNamedFunction('__phpc_spl_autoload_register_apply');
        if (null !== $registerProbe && $registerProbe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementRegisterApplyBridge($context);
        self::implementUnregisterApplyBridge($context);
        self::implementDispatch($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    public static function loadDepth(Context $context): Value
    {
        self::ensureJitHelperCompiled($context);

        return $context->builder->call(self::helperFunction($context, self::DEPTH_HELPER));
    }

    public static function loadMetaAt(Context $context, $i32, Value $index): Value
    {
        self::ensureJitHelperCompiled($context);
        $i64 = $context->getTypeFromString('int64');
        $idxI64 = $context->builder->sext($index, $i64);
        $opaque = $context->builder->call(
            self::helperFunction($context, self::META_OPAQUE_AT_HELPER),
            $idxI64
        );
        $i8p = $context->getTypeFromString('int8*');

        return $context->builder->inttoptr($opaque, $i8p);
    }

    private static function implementRegisterApplyBridge(Context $context): void
    {
        $abiName = '__phpc_spl_autoload_register_apply';
        $voidTy = $context->getTypeFromString('void');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($voidTy, false, $i8p, $i8p, $i32);
        $fn = $context->module->addFunction($abiName, $ft);
        $context->registerFunction($abiName, $fn);

        $entry = $fn->appendBasicBlock('spl_reg_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $fnOpaque = $fn->getParam(0);
        $metaOpaque = $fn->getParam(1);
        $prepend = $fn->getParam(2);
        $fnBits = $context->builder->ptrtoint($fnOpaque, $i64);
        $metaBits = $context->builder->ptrtoint($metaOpaque, $i64);
        $prependBool = $context->builder->icmp(Builder::INT_NE, $prepend, $i32->constInt(0, false));
        $context->builder->call(
            self::helperFunction($context, self::REGISTER_HELPER),
            $fnBits,
            $metaBits,
            $prependBool
        );
        $context->builder->returnVoid();
    }

    private static function implementUnregisterApplyBridge(Context $context): void
    {
        $abiName = '__phpc_spl_autoload_unregister_apply';
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($i32, false, $i8p);
        $fn = $context->module->addFunction($abiName, $ft);
        $context->registerFunction($abiName, $fn);

        $entry = $fn->appendBasicBlock('spl_unreg_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $fnOpaque = $fn->getParam(0);
        $fnBits = $context->builder->ptrtoint($fnOpaque, $i64);
        $found = $context->builder->call(
            self::helperFunction($context, self::UNREGISTER_HELPER),
            $fnBits
        );
        $context->builder->returnValue($context->builder->zext($found, $i32));
    }

    private static function implementDispatch(Context $context): void
    {
        $abiName = '__phpc_spl_autoload_dispatch';
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $cbFnTy = $context->context->functionType($i32, false, $i8p, $sizeT);
        $cbPtrTy = $cbFnTy->pointerType(0);
        $ft = $context->context->functionType($i32, false, $i8p, $sizeT);
        $fn = $context->module->addFunction($abiName, $ft);
        $context->registerFunction($abiName, $fn);

        $entry = $fn->appendBasicBlock('spl_disp_entry');
        $bbLoopHead = $fn->appendBasicBlock('spl_disp_loop_head');
        $bbLoopBody = $fn->appendBasicBlock('spl_disp_loop_body');
        $bbCall = $fn->appendBasicBlock('spl_disp_call');
        $bbNext = $fn->appendBasicBlock('spl_disp_next');
        $bbRetZero = $fn->appendBasicBlock('spl_disp_ret_zero');
        $bbRetOne = $fn->appendBasicBlock('spl_disp_ret_one');

        $context->builder->positionAtEnd($entry);
        $classPtr = $fn->getParam(0);
        $classLen = $fn->getParam(1);
        $iSlot = $context->builder->alloca($i32, 1, 'spl_disp_i');
        $context->builder->store($i32->constInt(0, false), $iSlot);

        $badName = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $classPtr, $i8p->constNull()),
            $context->builder->icmp(Builder::INT_EQ, $classLen, $sizeT->constInt(0, false))
        );
        $context->builder->branchIf($badName, $bbRetZero, $bbLoopHead);

        $context->builder->positionAtEnd($bbLoopHead);
        $iVal = $context->builder->load($iSlot);
        $depth = $context->builder->call(self::helperFunction($context, self::DEPTH_HELPER));
        $inRange = $context->builder->icmp(Builder::INT_SLT, $iVal, $depth);
        $context->builder->branchIf($inRange, $bbLoopBody, $bbRetZero);

        $context->builder->positionAtEnd($bbLoopBody);
        $idxI64 = $context->builder->sext($iVal, $i64);
        $fnBits = $context->builder->call(
            self::helperFunction($context, self::FN_OPAQUE_AT_HELPER),
            $idxI64
        );
        $fnNull = $context->builder->icmp(Builder::INT_EQ, $fnBits, $i64->constInt(0, false));
        $context->builder->branchIf($fnNull, $bbNext, $bbCall);

        $context->builder->positionAtEnd($bbCall);
        $fnOpaque = $context->builder->inttoptr($fnBits, $i8p);
        $cb = $context->builder->pointerCast($fnOpaque, $cbPtrTy);
        $ret = self::emitIndirectCall($context, $cbFnTy, $cb, $classPtr, $classLen);
        $ok = $context->builder->icmp(Builder::INT_NE, $ret, $i32->constInt(0, false));
        $context->builder->branchIf($ok, $bbRetOne, $bbNext);

        $context->builder->positionAtEnd($bbNext);
        $context->builder->store(
            $context->builder->add($iVal, $i32->constInt(1, false)),
            $iSlot
        );
        $context->builder->branch($bbLoopHead);

        $context->builder->positionAtEnd($bbRetZero);
        $context->builder->returnValue($i32->constInt(0, false));

        $context->builder->positionAtEnd($bbRetOne);
        $context->builder->returnValue($i32->constInt(1, false));
    }

    private static function emitIndirectCall(Context $context, $fnTy, Value $fnPtr, Value ...$args): Value
    {
        $b = $context->builder;
        if (!$b instanceof LLVMBuilderImpl) {
            throw new \LogicException('LLVM builder required for spl_autoload indirect call');
        }
        $valueWrapper = $b->llvm->lib->makeArray(
            LLVMValueRef_ptr::class,
            array_map(static fn (Value $value) => $value->value, $args)
        );

        return $b->llvm->factory->value(
            $context->context,
            $b->llvm->lib->LLVMBuildCall2(
                $b->builder,
                $fnTy->type,
                $fnPtr->value,
                $valueWrapper,
                \count($args),
                ''
            )
        );
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after SplAutoloadJitHelper compile (#9238)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        $missing = false;
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::HELPER_PATH;
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path): void {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'SplAutoloadJitHelper.php');
            if (null === $block) {
                throw new \LogicException('SplAutoloadJitHelper.php parseAndCompile failed (#9238)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9238)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FNS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after SplAutoloadOutput bridge (#9238)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
