<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\VmStreamSupports;
use PHPCompiler\JIT\Context;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM stream capability probes — isatty, is_local, supports (#5343, #6035, #6173, #5062).
 *
 * Replaces __compiler_stream_isatty / __compiler_stream_is_local / __compiler_stream_supports
 * Handle table globals: StreamGlobalsJit.php (#5343 phase 5).
 *
 * php-src: ext/standard/streamsfuncs.c
 */
final class StreamCapsJit
{
    private const MAX_HANDLES = 256;

    private const GLOBAL_HANDLES = 'phpc_stream_handles';

    private const GLOBAL_PATHS = 'phpc_stream_paths';

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_stream_isatty',
        '__compiler_stream_is_local',
        '__compiler_stream_is_local_uri',
        '__compiler_stream_supports',
    ];

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_stream_isatty');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureExternGlobals($context);
        self::ensureLibc($context);

        self::implementIfMissing($context, '__compiler_stream_isatty', self::emitIsatty(...));
        self::implementIfMissing($context, '__compiler_stream_is_local', self::emitIsLocal(...));
        self::implementIfMissing($context, '__compiler_stream_is_local_uri', self::emitIsLocalUri(...));
        self::implementIfMissing($context, '__compiler_stream_supports', self::emitSupports(...));
    }

    /**
     * @param callable(Context, LlvmFunction): void $emit
     */
    private static function implementIfMissing(Context $context, string $name, callable $emit): void
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        $fn = self::declareFunction($context, $name);
        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function declareFunction(Context $context, string $name): LlvmFunction
    {
        try {
            return $context->lookupFunction($name);
        } catch (\Throwable) {
            // fall through
        }

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $ft = match ($name) {
            '__compiler_stream_supports' => $context->context->functionType($i32, false, $i64, $i64),
            '__compiler_stream_is_local_uri' => $context->context->functionType($i32, false, $i8p),
            default => $context->context->functionType($i32, false, $i64),
        };
        $fn = $context->module->addFunction($name, $ft);
        $context->registerFunction($name, $fn);

        return $fn;
    }

    private static function ensureLibc(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');

        foreach ([
            ['strncmp', $i32, [$i8p, $i8p, $sizeT]],
            ['fileno', $i32, [$i8p]],
            ['isatty', $i32, [$i32]],
            ['__phpc_resolve_stream', $i8p, [$i64]],
        ] as [$name, $ret, $params]) {
            self::ensureExternal($context, $name, $context->context->functionType($ret, false, ...$params));
        }
    }

    private static function ensureExternal(Context $context, string $name, $ft): void
    {
        try {
            $context->lookupFunction($name);
        } catch (\Throwable) {
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }

    private static function ensureExternGlobals(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $tableTy = $i8p->arrayType(self::MAX_HANDLES);
        foreach ([self::GLOBAL_HANDLES, self::GLOBAL_PATHS] as $name) {
            if (null !== $context->module->getNamedGlobal($name)) {
                continue;
            }
            $context->module->addGlobal($tableTy, $name);
        }
    }

    private static function loadTableSlot(Context $context, string $globalName, Value $handle): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $zero = $i64->constInt(0, false);
        $global = $context->module->getNamedGlobal($globalName);
        if (null === $global) {
            throw new \LogicException('StreamCapsJit: '.$globalName.' missing');
        }
        $slot = $context->builder->gep($global, $zero, $handle);

        return $context->builder->load($context->builder->bitcast($slot, $i8p->pointerType(0)));
    }

    private static function literalCstr(Context $context, string $text): Value
    {
        $i8p = $context->getTypeFromString('int8*');

        return $context->builder->pointerCast($context->constantFromString($text), $i8p);
    }

    /** @return Value i1 — true when strncmp(path, prefix, len) == 0 */
    private static function hasPrefix(Context $context, Value $path, string $prefix): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $i32->constInt(0, false);
        $rc = $context->builder->call(
            $context->lookupFunction('strncmp'),
            $path,
            self::literalCstr($context, $prefix),
            $sizeT->constInt(\strlen($prefix), false)
        );

        return $context->builder->icmp(Builder::INT_EQ, $rc, $zero);
    }

    private static function branchIfUrlPath(
        Context $context,
        LlvmFunction $fn,
        Value $path,
        BasicBlock $urlBb,
        BasicBlock $notUrlBb
    ): void {
        $i8p = $context->getTypeFromString('int8*');
        $nullPtr = $i8p->constNull();
        $pathNull = $context->builder->icmp(Builder::INT_EQ, $path, $nullPtr);
        $checkBb = $fn->appendBasicBlock('caps_url_check');
        $context->builder->branchIf($pathNull, $notUrlBb, $checkBb);

        $context->builder->positionAtEnd($checkBb);
        $isHttp = self::hasPrefix($context, $path, 'http://');
        $isHttps = self::hasPrefix($context, $path, 'https://');
        $isFtp = self::hasPrefix($context, $path, 'ftp://');
        $isFtps = self::hasPrefix($context, $path, 'ftps://');
        $isUrl = $context->builder->or(
            $context->builder->or($isHttp, $isHttps),
            $context->builder->or($isFtp, $isFtps)
        );
        $context->builder->branchIf($isUrl, $urlBb, $notUrlBb);
    }

    private static function emitIsatty(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('caps_isatty_entry');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $zero = $i32->constInt(0, false);
        $one = $i32->constInt(1, false);
        $nullPtr = $i8p->constNull();

        $fp = $context->builder->call($context->lookupFunction('__phpc_resolve_stream'), $handle);
        $fpNull = $context->builder->icmp(Builder::INT_EQ, $fp, $nullPtr);
        $failBb = $fn->appendBasicBlock('caps_isatty_fail');
        $filenoBb = $fn->appendBasicBlock('caps_isatty_fileno');
        $context->builder->branchIf($fpNull, $failBb, $filenoBb);

        $context->builder->positionAtEnd($filenoBb);
        $fd = $context->builder->call($context->lookupFunction('fileno'), $fp);
        $fdBad = $context->builder->icmp(Builder::INT_SLT, $fd, $zero);
        $ttyBb = $fn->appendBasicBlock('caps_isatty_do');
        $context->builder->branchIf($fdBad, $failBb, $ttyBb);

        $context->builder->positionAtEnd($ttyBb);
        $tty = $context->builder->call($context->lookupFunction('isatty'), $fd);
        $ok = $context->builder->icmp(Builder::INT_NE, $tty, $zero);
        $context->builder->returnValue($context->builder->select($ok, $one, $zero));

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($zero);
    }

    private static function emitIsLocal(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('caps_islocal_entry');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $zero = $i32->constInt(0, false);
        $one = $i32->constInt(1, false);
        $nullPtr = $i8p->constNull();
        $max = $i64->constInt(self::MAX_HANDLES, false);
        $zero64 = $i64->constInt(0, false);

        $badHandle = $context->builder->or(
            $context->builder->icmp(Builder::INT_SLE, $handle, $zero64),
            $context->builder->icmp(Builder::INT_SGE, $handle, $max)
        );
        $failBb = $fn->appendBasicBlock('caps_islocal_fail');
        $openBb = $fn->appendBasicBlock('caps_islocal_open');
        $context->builder->branchIf($badHandle, $failBb, $openBb);

        $context->builder->positionAtEnd($openBb);
        $fp = self::loadTableSlot($context, self::GLOBAL_HANDLES, $handle);
        $path = self::loadTableSlot($context, self::GLOBAL_PATHS, $handle);
        $fpNull = $context->builder->icmp(Builder::INT_EQ, $fp, $nullPtr);
        $pathNull = $context->builder->icmp(Builder::INT_EQ, $path, $nullPtr);
        $notOpen = $context->builder->and($fpNull, $pathNull);
        $pathBb = $fn->appendBasicBlock('caps_islocal_path');
        $context->builder->branchIf($notOpen, $failBb, $pathBb);

        $context->builder->positionAtEnd($pathBb);
        $urlBb = $fn->appendBasicBlock('caps_islocal_url');
        $localBb = $fn->appendBasicBlock('caps_islocal_local');
        self::branchIfUrlPath($context, $fn, $path, $urlBb, $localBb);

        $context->builder->positionAtEnd($urlBb);
        $context->builder->returnValue($zero);

        $context->builder->positionAtEnd($localBb);
        $context->builder->returnValue($one);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($zero);
    }

    private static function emitIsLocalUri(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('caps_islocal_uri_entry');
        $context->builder->positionAtEnd($entry);

        $path = $fn->getParam(0);
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $zero = $i32->constInt(0, false);
        $one = $i32->constInt(1, false);
        $nullPtr = $i8p->constNull();

        $pathNull = $context->builder->icmp(Builder::INT_EQ, $path, $nullPtr);
        $failBb = $fn->appendBasicBlock('caps_islocal_uri_fail');
        $pathBb = $fn->appendBasicBlock('caps_islocal_uri_path');
        $context->builder->branchIf($pathNull, $failBb, $pathBb);

        $context->builder->positionAtEnd($pathBb);
        $urlBb = $fn->appendBasicBlock('caps_islocal_uri_url');
        $localBb = $fn->appendBasicBlock('caps_islocal_uri_local');
        self::branchIfUrlPath($context, $fn, $path, $urlBb, $localBb);

        $context->builder->positionAtEnd($urlBb);
        $context->builder->returnValue($zero);

        $context->builder->positionAtEnd($localBb);
        $context->builder->returnValue($one);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($zero);
    }

    private static function emitSupports(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('caps_supports_entry');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $feature = $fn->getParam(1);
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $zero = $i32->constInt(0, false);
        $one = $i32->constInt(1, false);
        $nullPtr = $i8p->constNull();
        $max = $i64->constInt(self::MAX_HANDLES, false);
        $zero64 = $i64->constInt(0, false);

        $badHandle = $context->builder->or(
            $context->builder->icmp(Builder::INT_SLE, $handle, $zero64),
            $context->builder->icmp(Builder::INT_SGE, $handle, $max)
        );
        $failBb = $fn->appendBasicBlock('caps_supports_fail');
        $openBb = $fn->appendBasicBlock('caps_supports_open');
        $context->builder->branchIf($badHandle, $failBb, $openBb);

        $context->builder->positionAtEnd($openBb);
        $fp = self::loadTableSlot($context, self::GLOBAL_HANDLES, $handle);
        $notOpen = $context->builder->icmp(Builder::INT_EQ, $fp, $nullPtr);
        $dispatchBb = $fn->appendBasicBlock('caps_supports_dispatch');
        $context->builder->branchIf($notOpen, $failBb, $dispatchBb);

        $context->builder->positionAtEnd($dispatchBb);
        $path = self::loadTableSlot($context, self::GLOBAL_PATHS, $handle);
        $featureI32 = $context->builder->trunc($feature, $i32);

        $defaultBb = $fn->appendBasicBlock('caps_supports_default');
        $lockBb = $fn->appendBasicBlock('caps_supports_lock');
        $filterBb = $fn->appendBasicBlock('caps_supports_filter');
        $metaBb = $fn->appendBasicBlock('caps_supports_meta');
        $classifyBb = $fn->appendBasicBlock('caps_supports_classify');

        $isLock = $context->builder->icmp(
            Builder::INT_EQ,
            $featureI32,
            $i32->constInt(VmStreamSupports::STREAM_LOCK, false)
        );
        $context->builder->branchIf($isLock, $lockBb, $classifyBb);

        $context->builder->positionAtEnd($classifyBb);
        $isFilter = $context->builder->icmp(
            Builder::INT_EQ,
            $featureI32,
            $i32->constInt(VmStreamSupports::STREAM_META_SEEKABLE, false)
        );
        $metaRangeBb = $fn->appendBasicBlock('caps_supports_meta_range');
        $context->builder->branchIf($isFilter, $filterBb, $metaRangeBb);

        $context->builder->positionAtEnd($metaRangeBb);
        $isMeta = $context->builder->and(
            $context->builder->icmp(
                Builder::INT_SGE,
                $featureI32,
                $i32->constInt(VmStreamSupports::STREAM_META_TOUCH, false)
            ),
            $context->builder->icmp(
                Builder::INT_SLE,
                $featureI32,
                $i32->constInt(VmStreamSupports::STREAM_META_ACCESS, false)
            )
        );
        $context->builder->branchIf($isMeta, $metaBb, $defaultBb);

        self::emitSupportsLock($context, $fn, $fp, $path, $failBb, $lockBb);
        self::emitSupportsFilter($context, $fn, $path, $failBb, $filterBb);
        self::emitSupportsMetadata($context, $fn, $path, $failBb, $metaBb);

        $context->builder->positionAtEnd($defaultBb);
        $context->builder->returnValue($zero);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($zero);
    }

    private static function emitSupportsLock(
        Context $context,
        LlvmFunction $fn,
        Value $fp,
        Value $path,
        BasicBlock $failBb,
        BasicBlock $lockBb
    ): void {
        $context->builder->positionAtEnd($lockBb);
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $zero = $i32->constInt(0, false);
        $one = $i32->constInt(1, false);
        $nullPtr = $i8p->constNull();

        $fpNull = $context->builder->icmp(Builder::INT_EQ, $fp, $nullPtr);
        $pathNull = $context->builder->icmp(Builder::INT_EQ, $path, $nullPtr);
        $bad = $context->builder->or($fpNull, $pathNull);
        $phpBb = $fn->appendBasicBlock('caps_supports_lock_php');
        $filenoBb = $fn->appendBasicBlock('caps_supports_lock_fileno');
        $context->builder->branchIf($bad, $failBb, $phpBb);

        $context->builder->positionAtEnd($phpBb);
        $isPhp = self::hasPrefix($context, $path, 'php://');
        $context->builder->branchIf($isPhp, $failBb, $filenoBb);

        $context->builder->positionAtEnd($filenoBb);
        $fd = $context->builder->call($context->lookupFunction('fileno'), $fp);
        $fdOk = $context->builder->icmp(Builder::INT_SGE, $fd, $zero);
        $context->builder->returnValue($context->builder->select($fdOk, $one, $zero));
    }

    private static function emitSupportsFilter(
        Context $context,
        LlvmFunction $fn,
        Value $path,
        BasicBlock $failBb,
        BasicBlock $filterBb
    ): void {
        $context->builder->positionAtEnd($filterBb);
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $zero = $i32->constInt(0, false);
        $one = $i32->constInt(1, false);
        $nullPtr = $i8p->constNull();

        $pathNull = $context->builder->icmp(Builder::INT_EQ, $path, $nullPtr);
        $checkBb = $fn->appendBasicBlock('caps_supports_seek_check');
        $context->builder->branchIf($pathNull, $failBb, $checkBb);

        $context->builder->positionAtEnd($checkBb);
        $isInput = self::hasPrefix($context, $path, 'php://input');
        $isOutput = self::hasPrefix($context, $path, 'php://output');
        $isStdin = self::uriEquals($context, $path, 'php://stdin');
        $isTcp = self::hasPrefix($context, $path, 'tcp://');
        $isUdp = self::hasPrefix($context, $path, 'udp://');
        $isUnix = self::hasPrefix($context, $path, 'unix://');
        $isSsl = self::hasPrefix($context, $path, 'ssl://');
        $isTls = self::hasPrefix($context, $path, 'tls://');
        $nonSeekable = $context->builder->or(
            $isInput,
            $context->builder->or(
                $isOutput,
                $context->builder->or(
                    $isStdin,
                    $context->builder->or(
                        $isTcp,
                        $context->builder->or($isUdp, $context->builder->or($isUnix, $context->builder->or($isSsl, $isTls)))
                    )
                )
            )
        );
        $context->builder->returnValue($context->builder->select($nonSeekable, $zero, $one));
    }

    private static function uriEquals(Context $context, Value $path, string $uri): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $cmp = $context->builder->call(
            $context->lookupFunction('strcmp'),
            $path,
            $context->builder->pointerCast($context->constantFromString($uri), $context->getTypeFromString('int8*'))
        );

        return $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
    }

    private static function emitSupportsMetadata(
        Context $context,
        LlvmFunction $fn,
        Value $path,
        BasicBlock $failBb,
        BasicBlock $metaBb
    ): void {
        $context->builder->positionAtEnd($metaBb);
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $zero = $i32->constInt(0, false);
        $one = $i32->constInt(1, false);
        $nullPtr = $i8p->constNull();

        $pathNull = $context->builder->icmp(Builder::INT_EQ, $path, $nullPtr);
        $phpBb = $fn->appendBasicBlock('caps_supports_meta_php');
        $okBb = $fn->appendBasicBlock('caps_supports_meta_ok');
        $context->builder->branchIf($pathNull, $failBb, $phpBb);

        $context->builder->positionAtEnd($phpBb);
        $isPhp = self::hasPrefix($context, $path, 'php://');
        $context->builder->branchIf($isPhp, $failBb, $okBb);

        $context->builder->positionAtEnd($okBb);
        $context->builder->returnValue($one);
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StreamCapsJit implement');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
