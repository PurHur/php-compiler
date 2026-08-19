<?php

declare(strict_types=1);

namespace PHPCompiler\ext\hash;

use PHPCompiler\ext\standard\JitFileGetContents;
use PHPCompiler\ext\standard\JitHash;
use PHPCompiler\ext\standard\JitIntdiv;
use PHPCompiler\ext\standard\JitStreamIoKernel;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\HashContextEmbedBridge;
use PHPCompiler\JIT\Builtin\HashContextRuntime;
use PHPCompiler\JIT\Builtin\StreamReadRuntime;
use PHPCompiler\JIT\Builtin\StringHashCrypto;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitResourceArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for hash_init/update/update_file/update_stream/final/copy (#7174, #3357, #32464, #32483). */
final class JitHashContext
{
    private static int $updateFileSerial = 0;

    private static int $updateStreamSerial = 0;
    private const INIT_HELPER = 'PHPCompiler\\ext\\hash\\HashContextJitHelper::init';

    private const UPDATE_HELPER = 'PHPCompiler\\ext\\hash\\HashContextJitHelper::update';

    private const FINALIZE_HELPER = 'PHPCompiler\\ext\\hash\\HashContextJitHelper::finalize';

    private const MARK_FINAL_HELPER = 'PHPCompiler\\ext\\hash\\HashContextJitHelper::markFinalized';

    private const COPY_HELPER = 'PHPCompiler\\ext\\hash\\HashContextJitHelper::copy';

    public static function dispatch(Context $context, string $name, JITVariable ...$args): Value
    {
        HashContextRuntime::ensureLinked($context);

        return match ($name) {
            'hash_init' => self::init($context, ...$args),
            'hash_update' => self::update($context, ...$args),
            'hash_update_file' => self::updateFile($context, ...$args),
            'hash_update_stream' => self::updateStream($context, ...$args),
            'hash_final' => self::final($context, ...$args),
            'hash_copy' => self::copy($context, ...$args),
            default => throw new \LogicException($name.'() JIT dispatch missing (#3357)'),
        };
    }

    public static function init(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                $argc < 1
                    ? 'hash_init() expects at least 1 argument, %d given'
                    : 'hash_init() expects at most 4 arguments, %d given',
                $argc
            ));
        }
        HashContextEmbedBridge::ensureLinked($context);
        // Z_PARAM_STR $algo — non-strict null is E_DEPRECATED + '' then ValueError (#21572).
        $algoStr = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'hash_init', 0, 'algo')
            : JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'hash_init', 0, 'algo');
        $i64 = $context->getTypeFromString('int64');
        $flagsLong = $i64->constInt(0, false);
        if (isset($args[1])) {
            $flagsLong = JitLongArg::lower($context, $args[1], 'hash_init(): Argument #2 ($flags)');
        }
        $keyStr = self::emptyString($context);
        if (isset($args[2])) {
            $keyStr = $context->callerStrictTypes
                ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[2], 'hash_init', 2, 'key')
                : JitStringBuiltinArg::lowerTrimFamilyString($context, $args[2], 'hash_init', 2, 'key');
        }
        // $options (arg 4) accepted for arity/stub parity — unused for sha256/sha1/md5.
        $handle = self::callHelper($context, self::INIT_HELPER, $algoStr, $flagsLong, $keyStr);

        $objectType = $context->type->object;
        $className = HashContextJitSupport::CLASS_NAME;
        $classId = $objectType->lookup($className);
        $obj = $objectType->allocate($classId);
        $objectType->markObjectConstructed($obj);
        self::storeHandle($context, $obj, $handle);
        self::storeStringProperty($context, $obj, HashContextJitSupport::PROP_ALGO, $algoStr);
        self::storeStringProperty(
            $context,
            $obj,
            HashContextJitSupport::PROP_BUF,
            self::emptyString($context)
        );
        self::storeStringProperty($context, $obj, HashContextJitSupport::PROP_KEY, $keyStr);
        self::storeNativeLongProperty(
            $context,
            $obj,
            HashContextJitSupport::PROP_HMAC,
            $context->builder->and($flagsLong, $i64->constInt(VmHashContext::HASH_HMAC, false))
        );

        return self::boxObject($context, $obj);
    }

    public static function update(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        // php-src ext/hash/hash.stub.php — ArgumentCountError (#28315).
        if (2 !== $argc) {
            $slot = JitValueBox::alloc($context);
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                \sprintf('hash_update() expects exactly 2 arguments, %d given', $argc)
            );

            return $slot;
        }
        HashContextEmbedBridge::ensureLinked($context);
        $obj = self::readContextObject($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        // Z_PARAM_STR $data — non-strict null is E_DEPRECATED + '' on 8.4 (#21557, reverts #20195).
        $chunkStr = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[1], 'hash_update', 1, 'data')
            : JitStringBuiltinArg::lowerTrimFamilyString($context, $args[1], 'hash_update', 1, 'data');
        self::applyUpdateChunk($context, $obj, $handle, $chunkStr);

        return self::returnTrue($context);
    }

    /**
     * hash_update_file() — feed file bytes into HashContext (php-src ext/hash/hash.c; #32464 leftover of #3357).
     *
     * Optional $stream_context is accepted for stub parity; local paths ignore wrapper options
     * (same as {@see hash_update_file::execute}).
     */
    public static function updateFile(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            $slot = JitValueBox::alloc($context);
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                $argc < 2
                    ? \sprintf('hash_update_file() expects at least 2 arguments, %d given', $argc)
                    : \sprintf('hash_update_file() expects at most 3 arguments, %d given', $argc)
            );

            return $slot;
        }
        if (isset($args[2])) {
            $rejected = self::rejectNonResourceOrNullStreamContext($context, $args[2]);
            if (null !== $rejected) {
                return $rejected;
            }
        }
        HashContextEmbedBridge::ensureLinked($context);
        $obj = self::readContextObject($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        $path = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[1], 'hash_update_file', 1, 'filename')
            : JitStringBuiltinArg::lowerTrimFamilyString($context, $args[1], 'hash_update_file', 1, 'filename');
        $contentsPtr = JitFileGetContents::invoke($context, $path);

        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($contentsPtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $stringTag = $i8->constInt(JITVariable::TYPE_STRING, false);
        $isString = $context->builder->icmp(Builder::INT_EQ, $typeByte, $stringTag);

        $id = (string) (++self::$updateFileSerial);
        $failBlock = BasicBlockHelper::append($context, 'huf_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'huf_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'huf_done_'.$id);
        $context->builder->branchIf($isString, $okBlock, $failBlock);

        $context->builder->positionAtEnd($failBlock);
        $failResult = self::boxedFalse($context);
        $failTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $chunkStr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $contentsPtr
        );
        self::applyUpdateChunk($context, $obj, $handle, $chunkStr);
        $okResult = self::returnTrue($context);
        $okTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($failResult->typeOf());
        $phi->addIncoming($failResult, $failTail);
        $phi->addIncoming($okResult, $okTail);

        return $phi;
    }

    /**
     * hash_update_stream() — feed stream bytes into HashContext (php-src ext/hash/hash.c; #32483 leftover of #3357 / #6681).
     *
     * Reads via `__compiler_stream_get_contents` (current offset, optional `$length`,
     * no seek) then {@see applyUpdateChunk()} like {@see updateFile()}. Thin AOT uses
     * {@see JitStreamIoKernel::implementStreamGetContentsForce()} (peer #27437 / #20982).
     */
    public static function updateStream(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            $slot = JitValueBox::alloc($context);
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                $argc < 2
                    ? \sprintf('hash_update_stream() expects at least 2 arguments, %d given', $argc)
                    : \sprintf('hash_update_stream() expects at most 3 arguments, %d given', $argc)
            );

            return $slot;
        }
        JitResourceArg::rejectEnumCaseOperand($context, $args[1], 'hash_update_stream', 1, 'stream');
        if (JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false)) {
            JitResourceArg::emitResourceTypeErrorAndAbort($context, 'hash_update_stream', 1, 'stream', 'null');

            return JitValueBox::pointer($context, JitValueBox::alloc($context));
        }
        HashContextEmbedBridge::ensureLinked($context);
        // Thin standalone AOT: libc FILE* stream_get_contents (peer #27437). NestedJIT
        // StreamReadRuntime wants StreamIoJitHelper::ftellArgv and aborts (#20982).
        if ($context->isThinStandaloneAotMain()) {
            JitStreamIoKernel::implementStreamGetContentsForce($context);
        } else {
            StreamReadRuntime::ensureLinked($context);
        }
        $obj = self::readContextObject($context, $args[0]);
        $hcHandle = self::loadHandle($context, $obj);
        $i64 = $context->getTypeFromString('int64');
        $streamHandle = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[1], 'hash_update_stream() stream'),
            $i64
        );
        $length = $i64->constInt(-1, true);
        if (isset($args[2])) {
            $length = JitIntdiv::lowerIntBuiltinArgForCaller(
                $context,
                $args[2],
                'hash_update_stream',
                3,
                'length'
            );
        }
        $contents = $context->builder->call(
            $context->lookupFunction('__compiler_stream_get_contents'),
            $streamHandle,
            $length,
            $i64->constInt(-1, true)
        );
        $strPtr = $context->getTypeFromString('__string__*');
        $failed = $context->builder->icmp(Builder::INT_EQ, $contents, $strPtr->constNull());

        $id = (string) (++self::$updateStreamSerial);
        $failBlock = BasicBlockHelper::append($context, 'hus_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'hus_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'hus_done_'.$id);
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $failResult = self::boxedFalse($context);
        $failTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $contents
        );
        self::applyUpdateChunk($context, $obj, $hcHandle, $owned);
        $map = $context->structFieldMap['__string__'];
        $nbytes = $context->builder->load($context->builder->structGep($owned, $map['length']));
        $okResult = self::boxedLong($context, $nbytes);
        $okTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($failResult->typeOf());
        $phi->addIncoming($failResult, $failTail);
        $phi->addIncoming($okResult, $okTail);

        return $phi;
    }

    public static function final(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        // php-src ext/hash/hash.stub.php — ArgumentCountError (#28315).
        if ($argc < 1 || $argc > 2) {
            $slot = JitValueBox::alloc($context);
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                $argc < 1
                    ? \sprintf('hash_final() expects at least 1 argument, %d given', $argc)
                    : \sprintf('hash_final() expects at most 2 arguments, %d given', $argc)
            );

            return $slot;
        }
        HashContextEmbedBridge::ensureLinked($context);

        $rawBool = $context->getTypeFromString('int1')->constInt(0, false);
        if (isset($args[1])) {
            $rawBool = JitBoolArg::lower($context, $args[1], 'hash_final(): Argument #2 ($binary)');
        }

        return self::finalLowering($context, $args[0], $rawBool);
    }

    /** Shared hash_final() body (#3357, #20200). */
    public static function finalLowering(Context $context, JITVariable $ctxArg, Value $rawBool): Value
    {
        // Thin standalone AOT: NestedJIT HashContextJitHelper::finalize segfaults (#3357 / #16075).
        if ($context->isThinStandaloneAotMain()) {
            return self::finalLoweringStandaloneAot($context, $ctxArg, $rawBool);
        }

        HashContextEmbedBridge::ensureLinked($context);
        $obj = self::readContextObject($context, $ctxArg);
        $handle = self::loadHandle($context, $obj);

        $digestRaw = self::callHelper($context, self::FINALIZE_HELPER, $handle, $rawBool);
        $digestStr = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $digestRaw);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $digestStr
        );

        return $ptr;
    }

    /**
     * Thin standalone AOT (`isThinStandaloneAotMain`, #20200): HashContextJitHelper::finalize
     * segfaults at execute (#3357). One-shot {@see JitHash::hash} on mirrored __hcBuf
     * (same __compiler_hash as hash_file; #32464).
     */
    private static function finalLoweringStandaloneAot(
        Context $context,
        JITVariable $ctxArg,
        Value $rawBool
    ): Value {
        StringHashCrypto::ensureLinked($context);
        $obj = self::readContextObject($context, $ctxArg);
        $algoPtr = self::loadStringProperty($context, $obj, HashContextJitSupport::PROP_ALGO);
        $dataPtr = self::loadStringProperty($context, $obj, HashContextJitSupport::PROP_BUF);
        $keyPtr = self::loadStringProperty($context, $obj, HashContextJitSupport::PROP_KEY);
        $hmacFlag = self::loadNativeLongProperty($context, $obj, HashContextJitSupport::PROP_HMAC);
        $i64 = $context->getTypeFromString('int64');
        $isHmac = $context->builder->icmp(
            Builder::INT_NE,
            $hmacFlag,
            $i64->constInt(0, false)
        );
        $hmacBb = BasicBlockHelper::append($context, 'hc_final_hmac');
        $plainBb = BasicBlockHelper::append($context, 'hc_final_plain');
        $doneBb = BasicBlockHelper::append($context, 'hc_final_done');
        $context->builder->branchIf($isHmac, $hmacBb, $plainBb);

        $context->builder->positionAtEnd($hmacBb);
        $hmacResult = JitHash::hashHmac($context, $algoPtr, $dataPtr, $keyPtr, $rawBool);
        $hmacTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($plainBb);
        $plainResult = JitHash::hash($context, $algoPtr, $dataPtr, $rawBool);
        $plainTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $phi = $context->builder->phi($plainResult->typeOf());
        $phi->addIncoming($hmacResult, $hmacTail);
        $phi->addIncoming($plainResult, $plainTail);

        return $phi;
    }

    public static function copy(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        // php-src ext/hash/hash.stub.php — ArgumentCountError (#28315).
        if (1 !== $argc) {
            $slot = JitValueBox::alloc($context);
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                \sprintf('hash_copy() expects exactly 1 argument, %d given', $argc)
            );

            return $slot;
        }
        HashContextEmbedBridge::ensureLinked($context);

        return self::copyLowering($context, $args[0]);
    }

    /** Shared hash_copy() body (#3357). */
    public static function copyLowering(Context $context, JITVariable $ctxArg): Value
    {
        HashContextEmbedBridge::ensureLinked($context);
        $src = self::readContextObject($context, $ctxArg);
        $handle = self::loadHandle($context, $src);
        $newHandle = self::callHelper($context, self::COPY_HELPER, $handle);

        $objectType = $context->type->object;
        $className = HashContextJitSupport::CLASS_NAME;
        $classId = $objectType->lookup($className);
        $dst = $objectType->allocate($classId);
        $objectType->markObjectConstructed($dst);
        self::storeHandle($context, $dst, $newHandle);
        self::storeStringProperty(
            $context,
            $dst,
            HashContextJitSupport::PROP_ALGO,
            self::loadStringProperty($context, $src, HashContextJitSupport::PROP_ALGO)
        );
        self::storeStringProperty(
            $context,
            $dst,
            HashContextJitSupport::PROP_BUF,
            self::loadStringProperty($context, $src, HashContextJitSupport::PROP_BUF)
        );
        self::storeStringProperty(
            $context,
            $dst,
            HashContextJitSupport::PROP_KEY,
            self::loadStringProperty($context, $src, HashContextJitSupport::PROP_KEY)
        );
        self::storeNativeLongProperty(
            $context,
            $dst,
            HashContextJitSupport::PROP_HMAC,
            self::loadNativeLongProperty($context, $src, HashContextJitSupport::PROP_HMAC)
        );

        return self::boxObject($context, $dst);
    }

    private static function applyUpdateChunk(
        Context $context,
        Value $obj,
        Value $handle,
        Value $chunkStr
    ): void {
        // Thin standalone AOT: NestedJIT HashContextJitHelper::update segfaults at execute
        // (peer finalize #3357 / #16075 / #20200). hash_finalStandaloneAot hashes __hcBuf.
        if (!$context->isThinStandaloneAotMain()) {
            self::callHelper($context, self::UPDATE_HELPER, $handle, $chunkStr);
        }
        $bufPtr = self::loadStringProperty($context, $obj, HashContextJitSupport::PROP_BUF);
        $map = $context->structFieldMap['__string__'];
        $leftLen = $context->builder->load($context->builder->structGep($bufPtr, $map['length']));
        $i64 = $context->getTypeFromString('int64');
        $isEmpty = $context->builder->icmp(
            Builder::INT_SLE,
            $leftLen,
            $i64->constInt(0, false)
        );
        $emptyBlock = BasicBlockHelper::append($context, 'hc_update_buf_empty');
        $appendBlock = BasicBlockHelper::append($context, 'hc_update_buf_append');
        $doneBlock = BasicBlockHelper::append($context, 'hc_update_buf_done');
        $context->builder->branchIf($isEmpty, $emptyBlock, $appendBlock);

        $context->builder->positionAtEnd($emptyBlock);
        self::storeStringProperty($context, $obj, HashContextJitSupport::PROP_BUF, $chunkStr);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($appendBlock);
        self::storeStringProperty(
            $context,
            $obj,
            HashContextJitSupport::PROP_BUF,
            self::appendStringPtr($context, $bufPtr, $chunkStr)
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
    }

    /**
     * Z_PARAM_RESOURCE_OR_NULL $stream_context — unused for local paths (#24563 / #32464).
     *
     * @return Value|null dummy slot when a compile-time TypeError was emitted
     */
    private static function rejectNonResourceOrNullStreamContext(Context $context, JITVariable $arg): ?Value
    {
        if (JITVariable::TYPE_NULL === $arg->type || $arg->isNullConstant) {
            return null;
        }
        $given = match ($arg->type) {
            JITVariable::TYPE_NATIVE_LONG => 'int',
            JITVariable::TYPE_NATIVE_DOUBLE => 'float',
            JITVariable::TYPE_NATIVE_BOOL => 'bool',
            JITVariable::TYPE_STRING => 'string',
            JITVariable::TYPE_HASHTABLE => 'array',
            default => null,
        };
        if (null === $given) {
            return null;
        }
        $slot = JitValueBox::alloc($context);
        ExceptionBridge::emitTypeErrorAndAbort(
            $context,
            'hash_update_file(): Argument #3 ($stream_context) must be of type resource or null, '.$given.' given'
        );

        return $slot;
    }

    private static function boxedFalse(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool(
            $context,
            $slot,
            $context->getTypeFromString('int1')->constInt(0, false)
        );

        return JitValueBox::pointer($context, $slot);
    }

    private static function boxedLong(Context $context, Value $long): Value
    {
        $slot = JitValueBox::alloc($context);
        $i64 = $context->getTypeFromString('int64');
        JitValueBox::writeLong(
            $context,
            $slot,
            $context->builder->truncOrBitCast($long, $i64)
        );

        return JitValueBox::pointer($context, $slot);
    }

    private static function appendStringPtr(Context $context, Value $left, Value $right): Value
    {
        $map = $context->structFieldMap['__string__'];
        $leftLen = $context->builder->load($context->builder->structGep($left, $map['length']));
        $rightLen = $context->builder->load($context->builder->structGep($right, $map['length']));
        $totalLen = $context->builder->add($leftLen, $rightLen);
        $dest = $context->builder->call($context->lookupFunction('__string__alloc'), $totalLen);
        $destPtr = $context->builder->structGep($dest, $map['value']);
        $context->builder->store(
            $totalLen,
            $context->builder->structGep($dest, $map['length'])
        );
        $leftPtr = $context->builder->structGep($left, $map['value']);
        $rightPtr = $context->builder->structGep($right, $map['value']);
        $context->intrinsic->memcpy($destPtr, $leftPtr, $leftLen, false);
        $context->intrinsic->memcpy(
            $context->builder->gep($destPtr, $leftLen),
            $rightPtr,
            $rightLen,
            false
        );

        return $dest;
    }

    private static function emptyString(Context $context): Value
    {
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->call(
            $context->lookupFunction('__string__alloc'),
            $i64->constInt(0, false)
        );
    }

    private static function storeStringProperty(
        Context $context,
        Value $obj,
        string $prop,
        Value $strPtr
    ): void {
        $strVar = new JITVariable(
            $context,
            JITVariable::TYPE_STRING,
            JITVariable::KIND_VALUE,
            $strPtr
        );
        $context->type->object->storeInstanceProperty(
            $obj,
            HashContextJitSupport::CLASS_NAME,
            $prop,
            $strVar
        );
    }

    private static function loadStringProperty(Context $context, Value $obj, string $prop): Value
    {
        $strVar = $context->type->object->propertyFetch(
            $obj,
            HashContextJitSupport::CLASS_NAME,
            $prop
        );

        return $context->helper->loadValue($strVar);
    }

    private static function storeNativeLongProperty(
        Context $context,
        Value $obj,
        string $prop,
        Value $longVal
    ): void {
        $handleVar = new JITVariable(
            $context,
            JITVariable::TYPE_NATIVE_LONG,
            JITVariable::KIND_VALUE,
            $longVal
        );
        $context->type->object->storeInstanceProperty(
            $obj,
            HashContextJitSupport::CLASS_NAME,
            $prop,
            $handleVar
        );
    }

    private static function loadNativeLongProperty(Context $context, Value $obj, string $prop): Value
    {
        $handleVar = $context->type->object->propertyFetch(
            $obj,
            HashContextJitSupport::CLASS_NAME,
            $prop
        );

        return $context->helper->loadValue($handleVar);
    }

    private static function storeHandle(Context $context, Value $obj, Value $handleI64): void
    {
        self::storeNativeLongProperty($context, $obj, HashContextJitSupport::PROP_ID, $handleI64);
    }

    private static function loadHandle(Context $context, Value $obj): Value
    {
        return self::loadNativeLongProperty($context, $obj, HashContextJitSupport::PROP_ID);
    }

    private static function callHelper(Context $context, string $logical, Value ...$args): Value
    {
        return JitNestedHelperCoerce::callHelper(
            $context,
            HashContextEmbedBridge::helperFunction($context, $logical),
            $args
        );
    }

    private static function readContextObject(Context $context, JITVariable $arg): Value
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);

        return $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
    }

    private static function boxObject(Context $context, Value $obj): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $ptr,
            $obj
        );

        return $ptr;
    }

    private static function returnTrue(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::writeBool(
            $context,
            $slot,
            $context->getTypeFromString('int1')->constInt(1, false)
        );

        return $ptr;
    }
}
