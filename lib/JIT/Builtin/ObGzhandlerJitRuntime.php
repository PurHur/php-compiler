<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\VM\ObStackLimits;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for ob_gzhandler() via ObGzhandlerJitHelper PHP (#4655, #8818, #9091, #9798, #12881).
 *
 * JIT embed and AOT standalone compile {@see \PHPCompiler\ext\standard\ObGzhandlerJitHelper}; thin LLVM bridges
 * forward the ABI. VM SSOT: {@see \PHPCompiler\ext\standard\VmObGzhandler}
 * php-src: ext/zlib/zlib.c — php_ob_gzhandler
 */
final class ObGzhandlerJitRuntime
{
    public const HANDLER_NONE = 0;

    public const HANDLER_GZHANDLER = 1;

    private const GLOBAL_HANDLER = '__phpc_ob_handler';

    private const HELPER_PATH = '/ext/standard/ObGzhandlerJitHelper.php';

    private const SERVER_HELPER_PATH = '/ext/standard/ObGzhandlerServerJitHelper.php';

    private const READ_ACCEPT_HELPER = 'PHPCompiler\\ext\\standard\\ObGzhandlerServerJitHelper::readAcceptEncodingFromServer';

    private const RESOLVE_ENCODING_HELPER = 'PHPCompiler\\ext\\standard\\ObGzhandlerJitHelper::resolveEncodingFromAcceptHeader';

    private const HANDLE_HELPER = 'PHPCompiler\\ext\\standard\\ObGzhandlerJitHelper::handle';

    private const FLUSH_HELPER = 'PHPCompiler\\ext\\standard\\ObGzhandlerJitHelper::flushBuffer';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::RESOLVE_ENCODING_HELPER,
        self::HANDLE_HELPER,
        self::FLUSH_HELPER,
    ];

    /** @var list<string> */
    private const EMBED_COMPILED_HELPERS = [
        self::READ_ACCEPT_HELPER,
    ];

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_ob_gzhandler',
        '__phpc_ob_gzhandler_flush',
        '__phpc_ob_start_with_gzhandler',
    ];

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_ob_gzhandler');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureGlobals($context);
        self::ensureJitHelperCompiled($context);
        self::implementObGzhandlerBridge($context);
        self::implementGzhandlerFlushBridge($context);
        self::implementObStartWithGzhandler($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    public static function handlerElemPtr(Context $context, Value $idx): Value
    {
        self::ensureGlobals($context);
        $i32 = $context->getTypeFromString('int32');
        $global = $context->module->getNamedGlobal(self::GLOBAL_HANDLER);
        $ptr = $context->builder->pointerCast($global, $i32->pointerType(0));

        return $context->builder->inBoundsGEP($ptr, $idx);
    }

    public static function isGzhandlerAt(Context $context, Value $idx): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $kind = $context->builder->load(self::handlerElemPtr($context, $idx));

        return $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i32->constInt(self::HANDLER_GZHANDLER, false)
        );
    }

    public static function emitApplyGzhandlerToString(Context $context, Value $content): Value
    {
        return $context->builder->call(
            $context->lookupFunction('__phpc_ob_gzhandler_flush'),
            $content
        );
    }

    public static function ensureJitHelperCompiledForLlvmBridge(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function resolveEncodingHelperFunction(Context $context): LlvmFunction
    {
        return self::helperFunction($context, self::RESOLVE_ENCODING_HELPER);
    }

    public static function handleHelperFunction(Context $context): LlvmFunction
    {
        return self::helperFunction($context, self::HANDLE_HELPER);
    }

    public static function flushHelperFunction(Context $context): LlvmFunction
    {
        return self::helperFunction($context, self::FLUSH_HELPER);
    }

    private static function implementObGzhandlerBridge(Context $context): void
    {
        $abiName = '__compiler_ob_gzhandler';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($strPtr, false, $strPtr, $i64);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('ogz_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $accept = self::emitReadAcceptEncodingViaHelper($context);
        $encoding = $context->builder->call(
            self::helperFunction($context, self::RESOLVE_ENCODING_HELPER),
            $accept
        );
        $result = $context->builder->call(
            self::helperFunction($context, self::HANDLE_HELPER),
            $fn->getParam(0),
            $fn->getParam(1),
            $encoding
        );
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    private static function implementGzhandlerFlushBridge(Context $context): void
    {
        $abiName = '__phpc_ob_gzhandler_flush';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($strPtr, false, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('ogf_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $accept = self::emitReadAcceptEncodingViaHelper($context);
        $encoding = $context->builder->call(
            self::helperFunction($context, self::RESOLVE_ENCODING_HELPER),
            $accept
        );
        $result = $context->builder->call(
            self::helperFunction($context, self::FLUSH_HELPER),
            $fn->getParam(0),
            $encoding
        );
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    private static function emitReadAcceptEncodingViaHelper(Context $context): Value
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $serverGlobal = $context->module->getNamedGlobal('sg_SERVER');
        if (null === $serverGlobal) {
            return self::emptyString($context);
        }
        $serverHt = $context->builder->load($serverGlobal);

        return $context->builder->call(
            self::helperFunction($context, self::READ_ACCEPT_HELPER),
            $serverHt
        );
    }

    private static function implementObStartWithGzhandler(Context $context): void
    {
        $abiName = '__phpc_ob_start_with_gzhandler';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $voidTy = $context->context->voidType();
        $ft = $context->context->functionType($voidTy, false);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('osg_bridge_entry');
        $skip = $fn->appendBasicBlock('osg_bridge_skip');
        $work = $fn->appendBasicBlock('osg_bridge_work');
        $context->builder->positionAtEnd($entry);
        $i32 = $context->getTypeFromString('int32');
        $levelPtr = self::levelPtr($context);
        $level = $context->builder->load($levelPtr);
        $atMax = $context->builder->icmp(
            Builder::INT_SGE,
            $level,
            $i32->constInt(ObStackLimits::MAX_DEPTH, false)
        );
        $context->builder->branchIf($atMax, $skip, $work);
        $context->builder->positionAtEnd($work);
        $context->builder->store($context->getTypeFromString('int64')->constInt(0, false), self::lenElemPtr($context, $level));
        $context->builder->store(
            $context->getTypeFromString('int8')->constInt(0, false),
            self::storageRowPtr($context, $level)
        );
        $context->builder->store(
            $i32->constInt(self::HANDLER_GZHANDLER, false),
            self::handlerElemPtr($context, $level)
        );
        $context->builder->store($context->builder->add($level, $i32->constInt(1, false)), $levelPtr);
        $context->builder->returnVoid();
        $context->builder->positionAtEnd($skip);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function levelPtr(Context $context): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $global = $context->module->getNamedGlobal(ObStorageGlobals::GLOBAL_LEVEL);

        return $context->builder->pointerCast($global, $i32->pointerType(0));
    }

    private static function lenElemPtr(Context $context, Value $idx): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $global = $context->module->getNamedGlobal(ObStorageGlobals::GLOBAL_LEN);
        $ptr = $context->builder->pointerCast($global, $i64->pointerType(0));

        return $context->builder->inBoundsGEP($ptr, $context->builder->sext($idx, $i64));
    }

    private static function storageRowPtr(Context $context, Value $idx): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $storage = $context->module->getNamedGlobal(ObStorageGlobals::GLOBAL_STORAGE);
        $rowTy = $i8->arrayType(ObStackLimits::BUF_SIZE);
        $storageTy = $rowTy->arrayType(ObStackLimits::MAX_DEPTH);
        $base = $context->builder->pointerCast($storage, $storageTy->pointerType(0));
        $row = $context->builder->inBoundsGEP($base, $i64->constInt(0, false), $context->builder->sext($idx, $i64));

        return $context->builder->pointerCast($row, $i8->pointerType(0));
    }

    private static function emptyString(Context $context): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(0, false),
            $context->builder->pointerCast($context->constantFromString(''), $i8p)
        );
    }

    private static function ensureGlobals(Context $context): void
    {
        ObStorageGlobals::ensureGlobals($context);
        $i32 = $context->getTypeFromString('int32');
        $depth = \PHPCompiler\VM\ObStackLimits::MAX_DEPTH;
        $htPtr = $context->getTypeFromString('__hashtable__*');

        if (null === $context->module->getNamedGlobal(self::GLOBAL_HANDLER)) {
            $handlerTy = $i32->arrayType($depth);
            $handler = $context->module->addGlobal($handlerTy, self::GLOBAL_HANDLER);
            $handler->setInitializer($handlerTy->constNull());
        }

        if (null === $context->module->getNamedGlobal('sg_SERVER')) {
            $server = $context->module->addGlobal($htPtr, 'sg_SERVER');
            $server->setInitializer($htPtr->constNull());
        }
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after ObGzhandlerJitHelper compile (#9798)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        $required = [...self::COMPILED_HELPERS, ...self::EMBED_COMPILED_HELPERS];
        $missing = false;
        foreach ($required as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        CallArgv::implement($context);
        StringZlib::ensureLinked($context);

        $runtime = $context->runtime;
        $repoRoot = \dirname(__DIR__, 3);
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $repoRoot): void {
            $corePath = $repoRoot.self::HELPER_PATH;
            $block = $runtime->parseAndCompile((string) \file_get_contents($corePath), 'ObGzhandlerJitHelper.php');
            if (null === $block) {
                throw new \LogicException('ObGzhandlerJitHelper.php parseAndCompile failed (#9798)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        $serverPath = $repoRoot.self::SERVER_HELPER_PATH;
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $serverPath): void {
            $block = $runtime->parseAndCompile((string) \file_get_contents($serverPath), 'ObGzhandlerServerJitHelper.php');
            if (null === $block) {
                throw new \LogicException('ObGzhandlerServerJitHelper.php parseAndCompile failed (#12881)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach ($required as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9798)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after ObGzhandlerJitRuntime bridge (#9798)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
