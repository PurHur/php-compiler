<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM implementation of __compiler_checkdnsrr (issue #5983 phase 2).
 *
 * Mirrors ext/standard/VmDns::checkdnsrrViaResQuery() via libc res_init/res_query.
 * php-src: ext/standard/dns.c — php_dns_check_record()
 */
final class CheckdnsrrRuntime
{
    private const DNS_CLASS_IN = 1;

    private const HOSTBUF_LEN = 256;

    private const TYPEBUF_LEN = 16;

    private const ANSWER_LEN = 1024;

    /** php-src php_dns_record_types (ext/standard/dns.c). */
    private const DNS_TYPES = [
        'A' => 1,
        'NS' => 2,
        'CNAME' => 5,
        'SOA' => 6,
        'PTR' => 12,
        'HINFO' => 13,
        'MX' => 15,
        'TXT' => 16,
        'AAAA' => 28,
        'SRV' => 33,
        'NAPTR' => 35,
        'A6' => 38,
        'ANY' => 255,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_checkdnsrr');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($i32, false, $strPtr, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction('__compiler_checkdnsrr', $ft);
        self::implementCheckdnsrr($context, $fn);
        self::registerLinkedRuntime($context);
    }

    private static function implementCheckdnsrr(Context $context, Value $fn): void
    {
        self::ensureLibcResolv($context);

        $entry = $fn->appendBasicBlock('cdrr_entry');
        $context->builder->positionAtEnd($entry);

        $hostname = $fn->getParam(0);
        $typeArg = $fn->getParam(1);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $voidPtr = $context->getTypeFromString('void*');
        $sizeT = $context->getTypeFromString('size_t');
        $strPtr = $context->getTypeFromString('__string__*');
        $zeroI64 = $i64->constInt(0, false);
        $zeroI32 = $i32->constInt(0, false);
        $oneI32 = $i32->constInt(1, false);

        $failBb = $fn->appendBasicBlock('cdrr_fail');
        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($zeroI32);
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($entry);
        $nullHost = $context->builder->icmp(Builder::INT_EQ, $hostname, $strPtr->constNull());
        $nullType = $context->builder->icmp(Builder::INT_EQ, $typeArg, $strPtr->constNull());
        $nullEither = $context->builder->or($nullHost, $nullType);
        $hostCopyBb = $fn->appendBasicBlock('cdrr_host_copy');
        $context->builder->branchIf($nullEither, $failBb, $hostCopyBb);

        $context->builder->positionAtEnd($hostCopyBb);
        $map = $context->structFieldMap['__string__'];
        $hostLen = $context->builder->load($context->builder->structGep($hostname, $map['length']));
        $hostLenOk = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGT, $hostLen, $zeroI64),
            $context->builder->icmp(Builder::INT_SLT, $hostLen, $i64->constInt(self::HOSTBUF_LEN, false))
        );
        $hostLenFailBb = $fn->appendBasicBlock('cdrr_host_len_fail');
        $hostLenOkBb = $fn->appendBasicBlock('cdrr_host_len_ok');
        $context->builder->branchIf($hostLenOk, $hostLenOkBb, $hostLenFailBb);

        $context->builder->positionAtEnd($hostLenFailBb);
        $context->builder->branch($failBb);
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($hostLenOkBb);
        $hostbuf = $context->builder->alloca($i8, self::HOSTBUF_LEN, 'cdrr_host');
        $hostValPtr = $context->builder->structGep($hostname, $map['value']);
        $hostSrc = $context->builder->pointerCast($hostValPtr, $i8p);
        $hostLen32 = $context->builder->trunc($hostLen, $i32);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->bytePtr($hostbuf),
            $context->bytePtr($hostSrc),
            $context->builder->zExt($hostLen32, $sizeT)
        );
        $context->builder->store($i8->constInt(0, false), $context->builder->gep($hostbuf, $hostLen32));

        $typeLen = $context->builder->load($context->builder->structGep($typeArg, $map['length']));
        $typeLenOk = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGT, $typeLen, $zeroI64),
            $context->builder->icmp(Builder::INT_SLT, $typeLen, $i64->constInt(self::TYPEBUF_LEN, false))
        );
        $typeLenFailBb = $fn->appendBasicBlock('cdrr_type_len_fail');
        $typeLenOkBb = $fn->appendBasicBlock('cdrr_type_len_ok');
        $context->builder->branchIf($typeLenOk, $typeLenOkBb, $typeLenFailBb);

        $context->builder->positionAtEnd($typeLenFailBb);
        $context->builder->branch($failBb);
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($typeLenOkBb);
        $typebuf = $context->builder->alloca($i8, self::TYPEBUF_LEN, 'cdrr_type');
        $typeValPtr = $context->builder->structGep($typeArg, $map['value']);
        $typeSrc = $context->builder->pointerCast($typeValPtr, $i8p);
        $typeLen32 = $context->builder->trunc($typeLen, $i32);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->bytePtr($typebuf),
            $context->bytePtr($typeSrc),
            $context->builder->zExt($typeLen32, $sizeT)
        );
        $context->builder->store($i8->constInt(0, false), $context->builder->gep($typebuf, $typeLen32));

        $upperInitBb = $fn->appendBasicBlock('cdrr_upper_init');
        $context->builder->branch($upperInitBb);
        $context->builder->clearInsertionPosition();

        $upperIdxSlot = $context->builder->alloca($i32, 1, 'cdrr_upper_i');
        $context->builder->store($zeroI32, $upperIdxSlot);
        $upperHeadBb = $fn->appendBasicBlock('cdrr_upper_head');
        $upperBodyBb = $fn->appendBasicBlock('cdrr_upper_body');
        $upperDoneBb = $fn->appendBasicBlock('cdrr_upper_done');
        $context->builder->positionAtEnd($upperInitBb);
        $context->builder->branch($upperHeadBb);
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($upperHeadBb);
        $upperIdx = $context->builder->load($upperIdxSlot);
        $upperCont = $context->builder->icmp(Builder::INT_SLT, $upperIdx, $typeLen32);
        $context->builder->branchIf($upperCont, $upperBodyBb, $upperDoneBb);
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($upperBodyBb);
        $chPtr = $context->builder->gep($typebuf, $upperIdx);
        $ch = $context->builder->load($chPtr);
        $isLower = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $ch, $i8->constInt(97, false)),
            $context->builder->icmp(Builder::INT_SLE, $ch, $i8->constInt(122, false))
        );
        $upperCh = $context->builder->sub($ch, $i8->constInt(32, false));
        $newCh = $context->builder->select($isLower, $upperCh, $ch);
        $context->builder->store($newCh, $chPtr);
        $context->builder->store($context->builder->add($upperIdx, $oneI32), $upperIdxSlot);
        $context->builder->branch($upperHeadBb);
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($upperDoneBb);
        $qtypeSlot = $context->builder->alloca($i32, 1, 'cdrr_qtype');
        $queryBb = $fn->appendBasicBlock('cdrr_query');
        self::emitResolveQtype($context, $fn, $typebuf, $failBb, $queryBb, $qtypeSlot);
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($queryBb);
        $qtype = $context->builder->load($qtypeSlot);
        $context->builder->call($context->lookupFunction('res_init'));
        $answer = $context->builder->alloca($i8, self::ANSWER_LEN, 'cdrr_ans');
        $rc = $context->builder->call(
            $context->lookupFunction('res_query'),
            $hostbuf,
            $i32->constInt(self::DNS_CLASS_IN, false),
            $qtype,
            $answer,
            $i32->constInt(self::ANSWER_LEN, false)
        );
        $ok = $context->builder->icmp(Builder::INT_SGT, $rc, $zeroI32);
        $ret = $context->builder->select($ok, $oneI32, $zeroI32);
        $context->builder->returnValue($ret);
        $context->builder->clearInsertionPosition();
    }

    private static function emitResolveQtype(
        Context $context,
        Value $fn,
        Value $typebuf,
        Value $failBb,
        Value $queryBb,
        Value $qtypeSlot
    ): void {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $zeroI32 = $i32->constInt(0, false);
        $headBb = $fn->appendBasicBlock('cdrr_qtype_head');
        $context->builder->branch($headBb);
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($headBb);
        $firstTryBb = $fn->appendBasicBlock('cdrr_qtype_try_0');
        $context->builder->branch($firstTryBb);
        $context->builder->clearInsertionPosition();

        $types = self::DNS_TYPES;
        $keys = array_keys($types);
        $last = \count($keys) - 1;
        foreach ($keys as $i => $name) {
            $tryBb = $fn->appendBasicBlock('cdrr_qtype_try_'.$i);
            $matchBb = $fn->appendBasicBlock('cdrr_qtype_match_'.$i);
            $context->builder->positionAtEnd($tryBb);
            $lit = $context->builder->pointerCast($context->constantFromString($name), $i8p);
            $cmp = $context->builder->call($context->lookupFunction('strcmp'), $typebuf, $lit);
            $isMatch = $context->builder->icmp(Builder::INT_EQ, $cmp, $zeroI32);
            if ($i === $last) {
                $context->builder->branchIf($isMatch, $matchBb, $failBb);
            } else {
                $nextTryBb = $fn->appendBasicBlock('cdrr_qtype_try_'.($i + 1));
                $context->builder->branchIf($isMatch, $matchBb, $nextTryBb);
            }
            $context->builder->clearInsertionPosition();

            $context->builder->positionAtEnd($matchBb);
            $context->builder->store($i32->constInt($types[$name], false), $qtypeSlot);
            $context->builder->branch($queryBb);
            $context->builder->clearInsertionPosition();
        }
    }

    private static function ensureLibcResolv(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $voidTy = $context->getTypeFromString('void');
        $voidPtr = $context->getTypeFromString('void*');
        $sizeT = $context->getTypeFromString('size_t');

        self::ensureExternal(
            $context,
            'res_init',
            $context->context->functionType($i32, false)
        );
        self::ensureExternal(
            $context,
            'res_query',
            $context->context->functionType($i32, false, $i8p, $i32, $i32, $i8p, $i32)
        );
        self::ensureExternal(
            $context,
            'memcpy',
            $context->context->functionType($voidPtr, false, $voidPtr, $voidPtr, $sizeT)
        );
        self::ensureExternal(
            $context,
            'strcmp',
            $context->context->functionType($i32, false, $i8p, $i8p)
        );
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

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction('__compiler_checkdnsrr');
        if (null === $fn) {
            throw new \LogicException('__compiler_checkdnsrr missing after CheckdnsrrRuntime LLVM implement');
        }
        $context->registerFunction('__compiler_checkdnsrr', $fn);
    }
}
