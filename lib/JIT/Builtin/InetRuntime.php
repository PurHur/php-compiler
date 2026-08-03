<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_ip2long/long2ip/inet_* via InetJitHelper PHP (#8969, #26010).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer Ctype #22626 / Frexp #22575).
 * InetJitHelper embeds IPv4 pure logic so thin NestedJIT is self-contained (#27088).
 * Call-site {@see ensureLinked} restores the caller insert block after bridge emit
 * (thin AOT: "Current basic block has no parent function", #27088 / peer #26884).
 * Embed and standalone AOT compile the same PHP bridge; no libc inet LLVM (#13193).
 * inet_pton/inet_ntop: IPv4 via __compiler_ip2long/long2ip + LLVM pack (#27172);
 * IPv6 via NestedJIT string|false (peer Hex2bin #27008) — no native chr|ord (#20452).
 * SSOT for VM: {@see \PHPCompiler\ext\standard\VmInet} / {@see VmInetPure}.
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(ip2long), long2ip, inet_ntop, inet_pton
 */
final class InetRuntime
{
    private const HELPER_PATH = '/ext/standard/InetJitHelper.php';

    private const IP2LONG_TAG = 'PHPCompiler\\ext\\standard\\InetJitHelper::ip2longTag';

    private const LONG2IP_TAG = 'PHPCompiler\\ext\\standard\\InetJitHelper::long2ipTag';

    private const LAST_INT = 'PHPCompiler\\ext\\standard\\InetJitHelper::lastInt';

    private const LAST_STRING = 'PHPCompiler\\ext\\standard\\InetJitHelper::lastString';

    private const INET_PTON_ARGV = 'PHPCompiler\\ext\\standard\\InetJitHelper::inetPtonArgv';

    private const INET_NTOP_ARGV = 'PHPCompiler\\ext\\standard\\InetJitHelper::inetNtopArgv';

    private const TAG_FALSE = 0;

    private const TAG_INT = 1;

    private const TAG_STRING = 2;

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::IP2LONG_TAG,
        self::LONG2IP_TAG,
        self::LAST_INT,
        self::LAST_STRING,
        self::INET_PTON_ARGV,
        self::INET_NTOP_ARGV,
    ];

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_ip2long',
        '__compiler_long2ip',
        '__compiler_inet_pton',
        '__compiler_inet_ntop',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_ip2long');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        // Preserve caller insert block — clearInsertionPosition alone orphans mid-emit (#27088).
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::ensureJitHelperCompiled($context);
        self::implementIfMissing($context, '__compiler_ip2long', self::implementIp2longBridge(...));
        self::implementIfMissing($context, '__compiler_long2ip', self::implementLong2ipBridge(...));
        self::implementIfMissing($context, '__compiler_inet_pton', self::implementInetPtonBridge(...));
        self::implementIfMissing($context, '__compiler_inet_ntop', self::implementInetNtopBridge(...));
        self::registerLinkedRuntime($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
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

        $valuePtr = $context->getTypeFromString('__value__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $voidTy = $context->getTypeFromString('void');

        return match ($name) {
            '__compiler_ip2long' => $context->module->addFunction(
                $name,
                $context->context->functionType($voidTy, false, $valuePtr, $strPtr)
            ),
            '__compiler_long2ip' => $context->module->addFunction(
                $name,
                $context->context->functionType($voidTy, false, $valuePtr, $i64)
            ),
            '__compiler_inet_pton', '__compiler_inet_ntop' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false, $strPtr)
            ),
            default => throw new \LogicException('Unknown inet JIT helper: '.$name),
        };
    }

    private static function implementIp2longBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('inet_ip2long_entry');
        $context->builder->positionAtEnd($entry);

        $out = $fn->getParam(0);
        $ip = $fn->getParam(1);
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');

        $tag = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::IP2LONG_TAG),
            [$ip]
        );
        $tagI32 = $context->builder->trunc($tag, $i32);

        $falseBb = $fn->appendBasicBlock('inet_ip2long_false');
        $intBb = $fn->appendBasicBlock('inet_ip2long_int');
        $doneBb = $fn->appendBasicBlock('inet_ip2long_done');

        $isFalse = $context->builder->icmp(Builder::INT_EQ, $tagI32, $i32->constInt(self::TAG_FALSE, false));
        $context->builder->branchIf($isFalse, $falseBb, $intBb);

        $context->builder->positionAtEnd($falseBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $out,
            $i32->constInt(0, false)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($intBb);
        $intResult = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::LAST_INT),
            []
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $out,
            $context->builder->sext($context->builder->trunc($intResult, $i32), $i64)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnVoid();
    }

    private static function implementLong2ipBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('inet_long2ip_entry');
        $context->builder->positionAtEnd($entry);

        $out = $fn->getParam(0);
        $addr = $fn->getParam(1);
        $i32 = $context->getTypeFromString('int32');

        $tag = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::LONG2IP_TAG),
            [$addr]
        );
        $tagI32 = $context->builder->trunc($tag, $i32);

        $falseBb = $fn->appendBasicBlock('inet_long2ip_false');
        $stringBb = $fn->appendBasicBlock('inet_long2ip_string');
        $doneBb = $fn->appendBasicBlock('inet_long2ip_done');

        $isFalse = $context->builder->icmp(Builder::INT_EQ, $tagI32, $i32->constInt(self::TAG_FALSE, false));
        $context->builder->branchIf($isFalse, $falseBb, $stringBb);

        $context->builder->positionAtEnd($falseBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $out,
            $i32->constInt(0, false)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($stringBb);
        $strResult = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::LAST_STRING),
            []
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $out,
            JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $strResult)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnVoid();
    }

    private static function implementInetPtonBridge(Context $context, LlvmFunction $fn): void
    {
        // IPv4 via working __compiler_ip2long + LLVM pack (#27172) — NestedJIT chr/lastInt
        // across internal calls zeros under thin AOT. IPv6 via NestedJIT inetPtonArgv.
        $entry = $fn->appendBasicBlock('inet_pton_entry');
        $context->builder->positionAtEnd($entry);

        $addr = $fn->getParam(0);
        $strPtr = $context->getTypeFromString('__string__*');
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $strMap = $context->structFieldMap['__string__'];
        $valueMap = $context->structFieldMap['__value__'];

        $box = JitValueBox::alloc($context);
        $boxPtr = JitValueBox::pointer($context, $box);
        $context->builder->call(
            $context->lookupFunction('__compiler_ip2long'),
            $boxPtr,
            $addr
        );

        $typeByte = $context->builder->load(
            $context->builder->structGep($boxPtr, $valueMap['type'])
        );
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );

        $ipv4Bb = $fn->appendBasicBlock('inet_pton_ipv4');
        $ipv6Bb = $fn->appendBasicBlock('inet_pton_ipv6');
        $context->builder->branchIf($isLong, $ipv4Bb, $ipv6Bb);

        $context->builder->positionAtEnd($ipv4Bb);
        $long = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $boxPtr
        );
        $packed = $context->builder->call(
            $context->lookupFunction('__string__alloc'),
            $sizeT->constInt(4, false)
        );
        $chars = $context->builder->pointerCast(
            $context->builder->structGep($packed, $strMap['value']),
            $i8->pointerType(0)
        );
        for ($i = 0; $i < 4; ++$i) {
            $shift = 24 - (8 * $i);
            $byte = $context->builder->trunc(
                $context->builder->and(
                    $context->builder->lShr($long, $i64->constInt($shift, false)),
                    $i64->constInt(0xFF, false)
                ),
                $i8
            );
            $at = $context->builder->gep($chars, $i32->constInt($i, false));
            $context->builder->store($byte, $at);
        }
        $context->builder->returnValue($packed);

        $context->builder->positionAtEnd($ipv6Bb);
        // Fast-path ::1 in LLVM — NestedJIT IPv6 helper aborts under thin AOT for some
        // shapes (#27172). Full NestedJIT path retained for other IPv6 literals.
        $addrLen = $context->builder->load(
            $context->builder->structGep($addr, $strMap['length'])
        );
        $isColon1Len = $context->builder->icmp(
            Builder::INT_EQ,
            $addrLen,
            $addrLen->typeOf()->constInt(3, false)
        );
        $colon1Bb = $fn->appendBasicBlock('inet_pton_colon1');
        $ipv6HelperBb = $fn->appendBasicBlock('inet_pton_ipv6_helper');
        $context->builder->branchIf($isColon1Len, $colon1Bb, $ipv6HelperBb);

        $context->builder->positionAtEnd($colon1Bb);
        $addrChars = $context->builder->pointerCast(
            $context->builder->structGep($addr, $strMap['value']),
            $i8->pointerType(0)
        );
        $c0 = $context->builder->load($context->builder->gep($addrChars, $i32->constInt(0, false)));
        $c1 = $context->builder->load($context->builder->gep($addrChars, $i32->constInt(1, false)));
        $c2 = $context->builder->load($context->builder->gep($addrChars, $i32->constInt(2, false)));
        $isColon1 = $context->builder->and(
            $context->builder->icmp(Builder::INT_EQ, $c0, $i8->constInt(58, false)),
            $context->builder->and(
                $context->builder->icmp(Builder::INT_EQ, $c1, $i8->constInt(58, false)),
                $context->builder->icmp(Builder::INT_EQ, $c2, $i8->constInt(49, false))
            )
        );
        $colon1OkBb = $fn->appendBasicBlock('inet_pton_colon1_ok');
        $context->builder->branchIf($isColon1, $colon1OkBb, $ipv6HelperBb);

        $context->builder->positionAtEnd($colon1OkBb);
        $v6 = $context->builder->call(
            $context->lookupFunction('__string__alloc'),
            $sizeT->constInt(16, false)
        );
        $v6chars = $context->builder->pointerCast(
            $context->builder->structGep($v6, $strMap['value']),
            $i8->pointerType(0)
        );
        for ($i = 0; $i < 15; ++$i) {
            $context->builder->store(
                $i8->constInt(0, false),
                $context->builder->gep($v6chars, $i32->constInt($i, false))
            );
        }
        $context->builder->store(
            $i8->constInt(1, false),
            $context->builder->gep($v6chars, $i32->constInt(15, false))
        );
        $context->builder->returnValue($v6);

        $context->builder->positionAtEnd($ipv6HelperBb);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::INET_PTON_ARGV),
            [$addr]
        );
        $falseBb = $fn->appendBasicBlock('inet_pton_ipv6_false');
        $okBb = $fn->appendBasicBlock('inet_pton_ipv6_ok');
        $isFalse = JitNestedHelperCoerce::isHelperResultNull($context, $raw);
        $context->builder->branchIf($isFalse, $falseBb, $okBb);

        $context->builder->positionAtEnd($falseBb);
        $context->builder->returnValue($strPtr->constNull());

        $context->builder->positionAtEnd($okBb);
        $context->builder->returnValue(
            JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw)
        );
    }

    private static function implementInetNtopBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('inet_ntop_entry');
        $context->builder->positionAtEnd($entry);

        $inAddr = $fn->getParam(0);
        $strPtr = $context->getTypeFromString('__string__*');
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $strMap = $context->structFieldMap['__string__'];

        $len = $context->builder->load(
            $context->builder->structGep($inAddr, $strMap['length'])
        );
        $is4 = $context->builder->icmp(Builder::INT_EQ, $len, $len->typeOf()->constInt(4, false));
        $is16 = $context->builder->icmp(Builder::INT_EQ, $len, $len->typeOf()->constInt(16, false));

        $ipv4Bb = $fn->appendBasicBlock('inet_ntop_ipv4');
        $check16Bb = $fn->appendBasicBlock('inet_ntop_check16');
        $ipv6Bb = $fn->appendBasicBlock('inet_ntop_ipv6');
        $failBb = $fn->appendBasicBlock('inet_ntop_fail');
        $context->builder->branchIf($is4, $ipv4Bb, $check16Bb);

        $context->builder->positionAtEnd($ipv4Bb);
        $chars = $context->builder->pointerCast(
            $context->builder->structGep($inAddr, $strMap['value']),
            $i8->pointerType(0)
        );
        $long = $i64->constInt(0, false);
        for ($i = 0; $i < 4; ++$i) {
            $byte = $context->builder->load(
                $context->builder->gep($chars, $i32->constInt($i, false))
            );
            $long = $context->builder->or(
                $long,
                $context->builder->shl(
                    $context->builder->zExt($byte, $i64),
                    $i64->constInt(24 - (8 * $i), false)
                )
            );
        }
        $box = JitValueBox::alloc($context);
        $boxPtr = JitValueBox::pointer($context, $box);
        $context->builder->call(
            $context->lookupFunction('__compiler_long2ip'),
            $boxPtr,
            $long
        );
        $context->builder->returnValue(
            $context->builder->call(
                $context->lookupFunction('__value__readString'),
                $boxPtr
            )
        );

        $context->builder->positionAtEnd($check16Bb);
        $context->builder->branchIf($is16, $ipv6Bb, $failBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($strPtr->constNull());

        $context->builder->positionAtEnd($ipv6Bb);
        // Fast-path ::1 binary (15×0x00 + 0x01) in LLVM (#27172).
        $chars6 = $context->builder->pointerCast(
            $context->builder->structGep($inAddr, $strMap['value']),
            $i8->pointerType(0)
        );
        $isColon1Bin = $i8->constInt(1, false); // accumulate as i1 via and
        $isColon1Bin = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->load($context->builder->gep($chars6, $i32->constInt(15, false))),
            $i8->constInt(1, false)
        );
        for ($i = 0; $i < 15; ++$i) {
            $isColon1Bin = $context->builder->and(
                $isColon1Bin,
                $context->builder->icmp(
                    Builder::INT_EQ,
                    $context->builder->load($context->builder->gep($chars6, $i32->constInt($i, false))),
                    $i8->constInt(0, false)
                )
            );
        }
        $colon1NtopBb = $fn->appendBasicBlock('inet_ntop_colon1');
        $ipv6HelperBb = $fn->appendBasicBlock('inet_ntop_ipv6_helper');
        $context->builder->branchIf($isColon1Bin, $colon1NtopBb, $ipv6HelperBb);

        $context->builder->positionAtEnd($colon1NtopBb);
        // Constant "::1" as __string__* (constantFromString is [N x i8]* — #27172).
        $i8p = $i8->pointerType(0);
        $colon1Str = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(3, false),
            $context->builder->pointerCast($context->constantFromString('::1'), $i8p)
        );
        $context->builder->returnValue($colon1Str);

        $context->builder->positionAtEnd($ipv6HelperBb);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::INET_NTOP_ARGV),
            [$inAddr]
        );
        $falseBb = $fn->appendBasicBlock('inet_ntop_ipv6_false');
        $okBb = $fn->appendBasicBlock('inet_ntop_ipv6_ok');
        $isFalse = JitNestedHelperCoerce::isHelperResultNull($context, $raw);
        $context->builder->branchIf($isFalse, $falseBb, $okBb);

        $context->builder->positionAtEnd($falseBb);
        $context->builder->returnValue($strPtr->constNull());

        $context->builder->positionAtEnd($okBb);
        $context->builder->returnValue(
            JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw)
        );
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#26010');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#26010'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after InetRuntime bridge (#8969)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
