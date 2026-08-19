<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_filter_validate_ip (#4403, #24650, #27207).
 *
 * Dynamic strings use {@see InetRuntime} {@see __compiler_inet_pton} in LLVM — NestedJIT
 * {@see FilterIpValidate::isValidInt} string indexing SIGSEGVs under thin AOT (#32571).
 * Const literals still fold via {@see VmFilter::isValidIpAddress} in {@see JitFilter}.
 *
 * php-src: ext/filter/logical_filters.c — php_filter_validate_ip
 */
final class StringFilterIp
{
    private const VALIDATE_PATH = '/ext/filter/FilterIpValidate.php';

    private const VALIDATE_IS_VALID_INT = 'PHPCompiler\\ext\\filter\\FilterIpValidate::isValidInt';

    private const FLAG_IPV4 = 0x00100000;

    private const FLAG_IPV6 = 0x00200000;

    private const FLAG_NO_RES_RANGE = 0x00400000;

    private const FLAG_NO_PRIV_RANGE = 0x00800000;

    private const FLAG_GLOBAL_RANGE = 0x10000000;

    private const SPECIAL_FLAGS = self::FLAG_NO_RES_RANGE | self::FLAG_NO_PRIV_RANGE | self::FLAG_GLOBAL_RANGE;

    /** @var list<string> */
    private const COMPILED_VALIDATE = [
        self::VALIDATE_IS_VALID_INT,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_filter_validate_ip',
    ];

    public static function ensureLinked(Context $context): void
    {
        $restore = BasicBlockHelper::tryGetInsertBlock($context);
        self::implement($context);
        if (null !== $restore) {
            BasicBlockHelper::restoreInsertBlock($context, $restore);
        }
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_filter_validate_ip');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::implementValidateBridge($context);
        self::registerLinkedRuntime($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementValidateBridge(Context $context): void
    {
        $abiName = '__compiler_filter_validate_ip';
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

        $entry = $fn->appendBasicBlock('filter_ip_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $flags = $fn->getParam(1);
        $specialMask = $i64->constInt(self::SPECIAL_FLAGS, false);
        $needsSpecial = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->and($flags, $specialMask),
            $i64->constInt(0, false)
        );
        $specialBb = $fn->appendBasicBlock('filter_ip_bridge_special');
        $inetBb = $fn->appendBasicBlock('filter_ip_bridge_inet');
        $context->builder->branchIf($needsSpecial, $specialBb, $inetBb);

        $context->builder->positionAtEnd($specialBb);
        self::emitNestedJitValidate($context, $fn);
        $context->builder->positionAtEnd($inetBb);
        self::emitInetPtonValidate($context, $fn);

        $context->registerFunction($abiName, $fn);
    }

    private static function emitNestedJitValidate(Context $context, LlvmFunction $fn): void
    {
        JitVmHelperLink::ensureCompiled($context, self::VALIDATE_PATH, self::COMPILED_VALIDATE, '#32571');
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $okBlock = $fn->appendBasicBlock('filter_ip_nj_ok');
        $failBlock = $fn->appendBasicBlock('filter_ip_nj_fail');

        $isValidInt = JitVmHelperLink::lookupCompiled($context, self::VALIDATE_IS_VALID_INT, '#32571');
        $rawOk = JitNestedHelperCoerce::callHelper(
            $context,
            $isValidInt,
            [$fn->getParam(0), $fn->getParam(1)]
        );
        $okI64 = JitNestedHelperCoerce::coerceBridgeResult($context, $rawOk, $i64);
        $ok = $context->builder->icmp(
            Builder::INT_NE,
            $okI64,
            $i64->constInt(0, false)
        );
        $context->builder->branchIf($ok, $okBlock, $failBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->returnValue($fn->getParam(0));

        $context->builder->positionAtEnd($failBlock);
        $context->builder->returnValue($strPtr->constNull());
    }

    private static function emitInetPtonValidate(Context $context, LlvmFunction $fn): void
    {
        InetRuntime::ensureLinked($context);
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $strMap = $context->structFieldMap['__string__'];

        $packed = $context->builder->call(
            $context->lookupFunction('__compiler_inet_pton'),
            $fn->getParam(0)
        );
        $isNull = $context->builder->icmp(Builder::INT_EQ, $packed, $strPtr->constNull());
        $failBb = $fn->appendBasicBlock('filter_ip_inet_fail');
        $checkFlagsBb = $fn->appendBasicBlock('filter_ip_inet_check_flags');
        $context->builder->branchIf($isNull, $failBb, $checkFlagsBb);

        $context->builder->positionAtEnd($checkFlagsBb);
        $packedLen = $context->builder->load(
            $context->builder->structGep($packed, $strMap['length'])
        );
        $flags = $fn->getParam(1);
        $ipv4Only = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->and($flags, $i64->constInt(self::FLAG_IPV4, false)),
            $i64->constInt(0, false)
        );
        $ipv6Only = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->and($flags, $i64->constInt(self::FLAG_IPV6, false)),
            $i64->constInt(0, false)
        );
        $isV4Len = $context->builder->icmp(
            Builder::INT_EQ,
            $packedLen,
            $sizeT->constInt(4, false)
        );
        $isV6Len = $context->builder->icmp(
            Builder::INT_EQ,
            $packedLen,
            $sizeT->constInt(16, false)
        );

        $okBb = $fn->appendBasicBlock('filter_ip_inet_ok');
        $rejectBb = $fn->appendBasicBlock('filter_ip_inet_reject');
        $v4CheckBb = $fn->appendBasicBlock('filter_ip_inet_v4_check');
        $v6CheckBb = $fn->appendBasicBlock('filter_ip_inet_v6_check');
        $bothOkBb = $fn->appendBasicBlock('filter_ip_inet_both_ok');

        // ipv4-only && !ipv6-only -> require 4-byte pack
        $needV4 = $context->builder->and($ipv4Only, $context->builder->not($ipv6Only));
        $context->builder->branchIf($needV4, $v4CheckBb, $v6CheckBb);

        $context->builder->positionAtEnd($v4CheckBb);
        $context->builder->branchIf($isV4Len, $okBb, $rejectBb);

        $context->builder->positionAtEnd($v6CheckBb);
        $needV6 = $context->builder->and($ipv6Only, $context->builder->not($ipv4Only));
        $context->builder->branchIf($needV6, $bothOkBb, $okBb);

        $context->builder->positionAtEnd($bothOkBb);
        $context->builder->branchIf($isV6Len, $okBb, $rejectBb);

        $context->builder->positionAtEnd($okBb);
        $context->builder->returnValue($fn->getParam(0));

        $context->builder->positionAtEnd($rejectBb);
        $context->builder->branch($failBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($strPtr->constNull());
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringFilterIp bridge (#4403)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
