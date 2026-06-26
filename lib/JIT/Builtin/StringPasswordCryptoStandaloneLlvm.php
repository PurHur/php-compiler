<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM password_hash/verify/crypt/get_info helpers for AOT standalone only (#6906, #9908).
 *
 * JIT embed uses {@see PasswordJitHelper} PHP; this TU quarantines libcrypt LLVM until
 * nested standalone can compile VmPassword helpers. php-src: ext/standard/password.c
 */
final class StringPasswordCryptoStandaloneLlvm
{
    private const BCRYPT_ITOA64 = './ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    private const PASSWORD_BCRYPT = 1;

    private const BCRYPT_DEFAULT_COST = 10;

    private const BCRYPT_MIN_COST = 4;

    private const BCRYPT_MAX_COST = 31;

    /** @var list<string> */
    private const FUNCTION_NAMES = [
        '__compiler_password_hash',
        '__compiler_password_verify',
        '__compiler_crypt',
        '__compiler_password_get_info',
        '__compiler_password_needs_rehash',
        '__compiler_password_algos',
    ];

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_password_hash');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerFunctions($context);

            return;
        }

        self::ensureLibc($context);
        self::ensureHelpers($context);

        self::implementIfMissing($context, '__compiler_password_hash', self::emitPasswordHash(...));
        self::implementIfMissing($context, '__compiler_password_verify', self::emitPasswordVerify(...));
        self::implementIfMissing($context, '__compiler_crypt', self::emitCrypt(...));
        self::implementIfMissing($context, '__compiler_password_get_info', self::emitPasswordGetInfo(...));
        self::implementIfMissing($context, '__compiler_password_needs_rehash', self::emitPasswordNeedsRehash(...));
        self::implementIfMissing($context, '__compiler_password_algos', self::emitPasswordAlgos(...));

        $context->builder->clearInsertionPosition();
    }

    private static function implementIfMissing(Context $context, string $name, callable $emit): void
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        $fn = $context->lookupFunction($name);
        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function registerFunctions(Context $context): void
    {
        foreach (self::FUNCTION_NAMES as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null !== $fn) {
                $context->registerFunction($name, $fn);
            }
        }
    }

    private static function emitPasswordHash(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('pw_hash_entry');
        $context->builder->positionAtEnd($entry);

        $password = $fn->getParam(0);
        $algo = $fn->getParam(1);
        $requestedCost = $fn->getParam(2);
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $nullStr = $strPtr->constNull();
        $one = $i64->constInt(1, false);
        $zeroI32 = $i32->constInt(0, false);

        $supported = $context->builder->icmp(
            Builder::INT_EQ,
            $algo,
            $i64->constInt(self::PASSWORD_BCRYPT, false)
        );
        $badAlgo = $fn->appendBasicBlock('pw_hash_bad_algo');
        $costPrep = $fn->appendBasicBlock('pw_hash_cost_prep');
        $context->builder->branchIf($supported, $costPrep, $badAlgo);

        $context->builder->positionAtEnd($badAlgo);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($costPrep);
        $useDefault = $context->builder->icmp(Builder::INT_SLE, $requestedCost, $i64->constInt(0, false));
        $defaultBb = $fn->appendBasicBlock('pw_hash_cost_default');
        $useReq = $fn->appendBasicBlock('pw_hash_cost_use_req');
        $costReady = $fn->appendBasicBlock('pw_hash_cost_ready');
        $context->builder->branchIf($useDefault, $defaultBb, $useReq);

        $context->builder->positionAtEnd($defaultBb);
        $context->builder->branch($costReady);

        $context->builder->positionAtEnd($useReq);
        $context->builder->branch($costReady);

        $context->builder->positionAtEnd($costReady);
        $defaultCostI32 = $i32->constInt(self::BCRYPT_DEFAULT_COST, false);
        $reqCostI32 = $context->builder->truncOrBitCast($requestedCost, $i32);
        $effCostPhi = $context->builder->phi($i32);
        $effCostPhi->addIncoming($defaultCostI32, $defaultBb);
        $effCostPhi->addIncoming($reqCostI32, $useReq);
        $effCostI64 = $context->builder->sextOrBitCast($effCostPhi, $i64);
        $tooLow = $context->builder->icmp(
            Builder::INT_SLT,
            $effCostI64,
            $i64->constInt(self::BCRYPT_MIN_COST, false)
        );
        $tooHigh = $context->builder->icmp(
            Builder::INT_SGT,
            $effCostI64,
            $i64->constInt(self::BCRYPT_MAX_COST, false)
        );
        $costBad = $context->builder->or($tooLow, $tooHigh);
        $costFail = $fn->appendBasicBlock('pw_hash_cost_fail');
        $body = $fn->appendBasicBlock('pw_hash_body');
        $context->builder->branchIf($costBad, $costFail, $body);

        $context->builder->positionAtEnd($costFail);
        TypeErrorRaise::ensureLinked($context);
        $msgBuf = $context->builder->alloca($i8, 128, 'pw_cost_err');
        $msgPtr = $context->builder->pointerCast($msgBuf, $i8p);
        $errFmt = self::cstr($context, 'Invalid bcrypt cost parameter specified: %lld');
        $context->builder->call(
            $context->lookupFunction('snprintf'),
            $msgPtr,
            $i64->constInt(128, false),
            $errFmt,
            $effCostI64
        );
        $msgLen = $context->builder->call($context->lookupFunction('strlen'), $msgPtr);
        $context->builder->call(
            $context->lookupFunction('__compiler_jit_raise_value_error'),
            $msgPtr,
            $context->builder->intCast($msgLen, $sizeT)
        );
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($body);
        $phrase = self::stringData($context, $password);
        $rnd = $context->builder->alloca($i8, 16, 'pw_rnd');
        $rndPtr = $context->builder->pointerCast($rnd, $i8p);
        $rndFail = $fn->appendBasicBlock('pw_hash_rnd_fail');
        $rndOk = $fn->appendBasicBlock('pw_hash_rnd_ok');
        self::fillRandom($context, $fn, $rndPtr, $i64->constInt(16, false), $rndOk, $rndFail);

        $context->builder->positionAtEnd($rndFail);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($rndOk);
        $salt22 = $context->builder->alloca($i8, 24, 'pw_salt22');
        self::emitBcryptEncodeSalt22($context, $fn, $rndPtr, $salt22);

        $setting = $context->builder->alloca($i8, 64, 'pw_setting');
        $settingPtr = $context->builder->pointerCast($setting, $i8p);
        $snprintf = $context->lookupFunction('snprintf');
        $fmt = self::cstr($context, '$2y$%02d$%s');
        $snLen = $context->builder->call(
            $snprintf,
            $settingPtr,
            $i64->constInt(64, false),
            $fmt,
            $effCostPhi,
            $context->builder->pointerCast($salt22, $i8p)
        );
        $snBad = $context->builder->icmp(Builder::INT_SGE, $snLen, $i64->constInt(64, false));
        $snFail = $fn->appendBasicBlock('pw_hash_sn_fail');
        $snOk = $fn->appendBasicBlock('pw_hash_sn_ok');
        $context->builder->branchIf($snBad, $snFail, $snOk);

        $context->builder->positionAtEnd($snFail);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($snOk);
        $result = $context->builder->call($context->lookupFunction('crypt'), $phrase, $settingPtr);
        $resultNull = $context->builder->icmp(Builder::INT_EQ, $result, $i8p->constNull());
        $star = $context->builder->load($result);
        $isStar = $context->builder->icmp(Builder::INT_EQ, $star, $i8->constInt(ord('*'), false));
        $cryptBad = $context->builder->or($resultNull, $isStar);
        $cryptFail = $fn->appendBasicBlock('pw_hash_crypt_fail');
        $cryptOk = $fn->appendBasicBlock('pw_hash_crypt_ok');
        $context->builder->branchIf($cryptBad, $cryptFail, $cryptOk);

        $context->builder->positionAtEnd($cryptFail);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($cryptOk);
        $context->builder->returnValue(self::stringFromCstr($context, $result));
    }

    private static function emitPasswordVerify(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('pw_verify_entry');
        $context->builder->positionAtEnd($entry);

        $password = $fn->getParam(0);
        $hash = $fn->getParam(1);
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $zero = $i32->constInt(0, false);
        $one = $i32->constInt(1, false);

        $stored = self::stringData($context, $hash);
        $storedLen = self::stringLen($context, $hash);
        $lenOk = $context->builder->icmp(
            Builder::INT_SGE,
            $storedLen,
            $context->getTypeFromString('int64')->constInt(29, false)
        );
        $prefix = self::cstr($context, '$2y$');
        $prefixOk = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->call(
                $context->lookupFunction('strncmp'),
                $stored,
                $prefix,
                $context->getTypeFromString('size_t')->constInt(4, false)
            ),
            $zero
        );
        $precond = $context->builder->and($lenOk, $prefixOk);
        $fail = $fn->appendBasicBlock('pw_verify_fail');
        $body = $fn->appendBasicBlock('pw_verify_body');
        $context->builder->branchIf($precond, $body, $fail);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($zero);

        $context->builder->positionAtEnd($body);
        $phrase = self::stringData($context, $password);
        $computed = $context->builder->call($context->lookupFunction('crypt'), $phrase, $stored);
        $computedNull = $context->builder->icmp(Builder::INT_EQ, $computed, $i8p->constNull());
        $star = $context->builder->load($computed);
        $isStar = $context->builder->icmp(
            Builder::INT_EQ,
            $star,
            $context->getTypeFromString('int8')->constInt(ord('*'), false)
        );
        $cryptBad = $context->builder->or($computedNull, $isStar);
        $cryptFail = $fn->appendBasicBlock('pw_verify_crypt_fail');
        $cmpBb = $fn->appendBasicBlock('pw_verify_cmp');
        $context->builder->branchIf($cryptBad, $cryptFail, $cmpBb);

        $context->builder->positionAtEnd($cryptFail);
        $context->builder->returnValue($zero);

        $context->builder->positionAtEnd($cmpBb);
        $match = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->call(
                $context->lookupFunction('strcmp'),
                $computed,
                $stored
            ),
            $zero
        );
        $context->builder->returnValue($context->builder->select($match, $one, $zero));
    }

    private static function emitCrypt(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('pw_crypt_entry');
        $context->builder->positionAtEnd($entry);

        $password = $fn->getParam(0);
        $salt = $fn->getParam(1);
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $star0 = self::literalString($context, '*0');

        $phrase = self::stringData($context, $password);
        $setting = self::stringData($context, $salt);
        $saltLen = self::stringLen($context, $salt);

        $len2 = $context->builder->icmp(
            Builder::INT_SGE,
            $saltLen,
            $context->getTypeFromString('int64')->constInt(2, false)
        );
        $b0 = $context->builder->load($setting);
        $b1 = $context->builder->load($context->builder->gep($setting, $i8->constInt(1, false)));
        $isStarPrefix = $context->builder->and(
            $context->builder->icmp(Builder::INT_EQ, $b0, $i8->constInt(ord('*'), false)),
            $context->builder->or(
                $context->builder->icmp(Builder::INT_EQ, $b1, $i8->constInt(ord('0'), false)),
                $context->builder->icmp(Builder::INT_EQ, $b1, $i8->constInt(ord('1'), false))
            )
        );
        $starEarly = $fn->appendBasicBlock('pw_crypt_star_early');
        $checkDollar = $fn->appendBasicBlock('pw_crypt_check_dollar');
        $context->builder->branchIf($isStarPrefix, $starEarly, $checkDollar);

        $context->builder->positionAtEnd($starEarly);
        $context->builder->returnValue($star0);

        $context->builder->positionAtEnd($checkDollar);
        $isDollar = $context->builder->icmp(Builder::INT_EQ, $b0, $i8->constInt(ord('$'), false));
        $dollarBb = $fn->appendBasicBlock('pw_crypt_dollar');
        $classicBb = $fn->appendBasicBlock('pw_crypt_classic');
        $context->builder->branchIf($isDollar, $dollarBb, $classicBb);

        $context->builder->positionAtEnd($dollarBb);
        $len4 = $context->builder->icmp(
            Builder::INT_SGE,
            $saltLen,
            $context->getTypeFromString('int64')->constInt(4, false)
        );
        $b2 = $context->builder->load($context->builder->gep($setting, $i8->constInt(2, false)));
        $b3 = $context->builder->load($context->builder->gep($setting, $i8->constInt(3, false)));
        $blowfish = $context->builder->and(
            $len4,
            $context->builder->and(
                $context->builder->icmp(Builder::INT_EQ, $b1, $i8->constInt(ord('2'), false)),
                $context->builder->icmp(Builder::INT_EQ, $b3, $i8->constInt(ord('$'), false))
            )
        );
        $md5 = $context->builder->and(
            $context->builder->icmp(
                Builder::INT_SGE,
                $saltLen,
                $context->getTypeFromString('int64')->constInt(3, false)
            ),
            $context->builder->and(
                $context->builder->icmp(Builder::INT_EQ, $b1, $i8->constInt(ord('1'), false)),
                $context->builder->icmp(Builder::INT_EQ, $b2, $i8->constInt(ord('$'), false))
            )
        );
        $modOk = $context->builder->or($blowfish, $md5);
        $modFail = $fn->appendBasicBlock('pw_crypt_mod_fail');
        $doCrypt = $fn->appendBasicBlock('pw_crypt_do');
        $context->builder->branchIf($modOk, $doCrypt, $modFail);

        $context->builder->positionAtEnd($modFail);
        $context->builder->returnValue($star0);

        $context->builder->positionAtEnd($classicBb);
        $classicOk = $len2;
        $classicFail = $fn->appendBasicBlock('pw_crypt_classic_fail');
        $classicCheck = $fn->appendBasicBlock('pw_crypt_classic_check');
        $context->builder->branchIf($classicOk, $classicCheck, $classicFail);

        $context->builder->positionAtEnd($classicFail);
        $context->builder->branch($modFail);

        $context->builder->positionAtEnd($classicCheck);
        $c0ok = self::isValidSaltChar($context, $b0);
        $c1ok = self::isValidSaltChar($context, $b1);
        $classicValid = $context->builder->and($c0ok, $c1ok);
        $context->builder->branchIf($classicValid, $doCrypt, $modFail);

        $context->builder->positionAtEnd($doCrypt);
        $result = $context->builder->call($context->lookupFunction('crypt'), $phrase, $setting);
        $resultNull = $context->builder->icmp(Builder::INT_EQ, $result, $i8p->constNull());
        $r0 = $context->builder->load($result);
        $bad = $context->builder->or(
            $resultNull,
            $context->builder->icmp(Builder::INT_EQ, $r0, $i8->constInt(ord('*'), false))
        );
        $cryptFail = $fn->appendBasicBlock('pw_crypt_fail');
        $cryptOk = $fn->appendBasicBlock('pw_crypt_ok');
        $context->builder->branchIf($bad, $cryptFail, $cryptOk);

        $context->builder->positionAtEnd($cryptFail);
        $context->builder->returnValue($star0);

        $context->builder->positionAtEnd($cryptOk);
        $context->builder->returnValue(self::stringFromCstr($context, $result));
    }

    private static function emitPasswordAlgos(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('pw_algos_entry');
        $context->builder->positionAtEnd($entry);

        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $nullHt = $htPtr->constNull();
        $htNull = $context->builder->icmp(Builder::INT_EQ, $ht, $nullHt);
        $fail = $fn->appendBasicBlock('pw_algos_fail');
        $ok = $fn->appendBasicBlock('pw_algos_ok');
        $context->builder->branchIf($htNull, $fail, $ok);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($nullHt);

        $context->builder->positionAtEnd($ok);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringAt'),
            $ht,
            $context->getTypeFromString('size_t')->constInt(0, false),
            self::literalString($context, '2y')
        );
        $context->builder->returnValue($ht);
    }

    private static function emitPasswordGetInfo(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('pw_info_entry');
        $context->builder->positionAtEnd($entry);

        $hash = $fn->getParam(0);
        $h = self::stringData($context, $hash);
        $len = self::stringLen($context, $hash);
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');

        $len3 = $context->builder->icmp(Builder::INT_SGE, $len, $i64->constInt(3, false));
        $dollar = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->load($h),
            $i8->constInt(ord('$'), false)
        );
        $pre = $context->builder->and($len3, $dollar);
        $unknownBb = $fn->appendBasicBlock('pw_info_unknown');
        $parseBb = $fn->appendBasicBlock('pw_info_parse');
        $context->builder->branchIf($pre, $parseBb, $unknownBb);

        $context->builder->positionAtEnd($unknownBb);
        $context->builder->returnValue(self::buildInfoUnknown($context, $fn));

        $context->builder->positionAtEnd($parseBb);
        $identBuf = $context->builder->alloca($i8, 32, 'pw_ident');
        $identPtr = $context->builder->pointerCast($identBuf, $context->getTypeFromString('int8*'));
        $start = $context->builder->gep($h, $i8->constInt(1, false));
        $endDollar = $context->builder->call($context->lookupFunction('strchr'), $start, $i8->constInt(ord('$'), false));
        $endNull = $context->builder->icmp(Builder::INT_EQ, $endDollar, $context->getTypeFromString('int8*')->constNull());
        $noIdent = $fn->appendBasicBlock('pw_info_no_ident');
        $haveIdent = $fn->appendBasicBlock('pw_info_have_ident');
        $context->builder->branchIf($endNull, $noIdent, $haveIdent);

        $context->builder->positionAtEnd($noIdent);
        $context->builder->returnValue(self::buildInfoUnknown($context, $fn));

        $context->builder->positionAtEnd($haveIdent);
        $idLen = $context->builder->sub(
            $context->builder->pointerCast($endDollar, $i64),
            $context->builder->pointerCast($start, $i64)
        );
        $tooLong = $context->builder->icmp(Builder::INT_SGE, $idLen, $i64->constInt(31, false));
        $idOk = $fn->appendBasicBlock('pw_info_id_ok');
        $context->builder->branchIf($tooLong, $noIdent, $idOk);

        $context->builder->positionAtEnd($idOk);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $identPtr,
            $start,
            $context->builder->truncOrBitCast($idLen, $context->getTypeFromString('size_t'))
        );
        $context->builder->store($i8->constInt(0, false), $context->builder->gep($identPtr, $idLen));

        $is2y = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->call($context->lookupFunction('strcmp'), $identPtr, self::cstr($context, '2y')),
            $context->getTypeFromString('int32')->constInt(0, false)
        );
        $bcryptBb = $fn->appendBasicBlock('pw_info_bcrypt');
        $argonBb = $fn->appendBasicBlock('pw_info_argon');
        $context->builder->branchIf($is2y, $bcryptBb, $argonBb);

        $context->builder->positionAtEnd($bcryptBb);
        $len60 = $context->builder->icmp(Builder::INT_EQ, $len, $i64->constInt(60, false));
        $y = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->load($context->builder->gep($h, $i8->constInt(2, false))),
            $i8->constInt(ord('y'), false)
        );
        $two = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->load($context->builder->gep($h, $i8->constInt(1, false))),
            $i8->constInt(ord('2'), false)
        );
        $bcryptValid = $context->builder->and($len60, $context->builder->and($two, $y));
        $bcryptOk = $fn->appendBasicBlock('pw_info_bcrypt_ok');
        $context->builder->branchIf($bcryptValid, $bcryptOk, $unknownBb);

        $context->builder->positionAtEnd($bcryptOk);
        $costSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($i64->constInt(self::BCRYPT_DEFAULT_COST, false), $costSlot);
        $context->builder->call(
            $context->lookupFunction('sscanf'),
            $h,
            self::cstr($context, '$2y$%lld$'),
            $costSlot
        );
        $context->builder->returnValue(self::buildInfoBcrypt($context, $costSlot));

        $context->builder->positionAtEnd($argonBb);
        $context->builder->returnValue(self::buildInfoUnknown($context, $fn));
    }

    private static function emitPasswordNeedsRehash(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('pw_nrh_entry');
        $context->builder->positionAtEnd($entry);

        $hash = $fn->getParam(0);
        $algo = $fn->getParam(1);
        $newCost = $fn->getParam(2);
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $zero = $i32->constInt(0, false);
        $one = $i32->constInt(1, false);
        $strPtr = $context->getTypeFromString('__string__*');

        $supported = $context->builder->icmp(
            Builder::INT_EQ,
            $algo,
            $i64->constInt(self::PASSWORD_BCRYPT, false)
        );
        $badAlgo = $fn->appendBasicBlock('pw_nrh_bad_algo');
        $body = $fn->appendBasicBlock('pw_nrh_body');
        $context->builder->branchIf($supported, $body, $badAlgo);

        $context->builder->positionAtEnd($badAlgo);
        $context->builder->returnValue($zero);

        $context->builder->positionAtEnd($body);
        $hashNull = $context->builder->icmp(Builder::INT_EQ, $hash, $strPtr->constNull());
        $nullYes = $fn->appendBasicBlock('pw_nrh_null_yes');
        $check = $fn->appendBasicBlock('pw_nrh_check');
        $context->builder->branchIf($hashNull, $nullYes, $check);

        $context->builder->positionAtEnd($nullYes);
        $context->builder->returnValue($one);

        $context->builder->positionAtEnd($check);
        $h = self::stringData($context, $hash);
        $len = self::stringLen($context, $hash);
        $i8 = $context->getTypeFromString('int8');
        $len60 = $context->builder->icmp(Builder::INT_EQ, $len, $i64->constInt(60, false));
        $prefix = self::cstr($context, '$2y$');
        $prefixOk = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->call(
                $context->lookupFunction('strncmp'),
                $h,
                $prefix,
                $context->getTypeFromString('size_t')->constInt(4, false)
            ),
            $zero
        );
        $bcrypt = $context->builder->and($len60, $prefixOk);
        $notBcrypt = $fn->appendBasicBlock('pw_nrh_not_bcrypt');
        $costCmp = $fn->appendBasicBlock('pw_nrh_cost');
        $context->builder->branchIf($bcrypt, $costCmp, $notBcrypt);

        $context->builder->positionAtEnd($notBcrypt);
        $context->builder->returnValue($one);

        $context->builder->positionAtEnd($costCmp);
        $effCost = BasicBlockHelper::entryAlloca($context, $i64);
        $newLeZero = $context->builder->icmp(Builder::INT_SLE, $newCost, $i64->constInt(0, false));
        $useDefault = $fn->appendBasicBlock('pw_nrh_default_cost');
        $useNew = $fn->appendBasicBlock('pw_nrh_use_new');
        $costReady = $fn->appendBasicBlock('pw_nrh_cost_ready');
        $context->builder->branchIf($newLeZero, $useDefault, $useNew);

        $context->builder->positionAtEnd($useDefault);
        $context->builder->store($i64->constInt(self::BCRYPT_DEFAULT_COST, false), $effCost);
        $context->builder->branch($costReady);

        $context->builder->positionAtEnd($useNew);
        $context->builder->store($newCost, $effCost);
        $context->builder->branch($costReady);

        $context->builder->positionAtEnd($costReady);
        $oldCostSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($i64->constInt(self::BCRYPT_DEFAULT_COST, false), $oldCostSlot);
        $len7 = $context->builder->icmp(Builder::INT_SGE, $len, $i64->constInt(7, false));
        $prefix2y = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->call(
                $context->lookupFunction('memcmp'),
                $h,
                $prefix,
                $context->getTypeFromString('size_t')->constInt(4, false)
            ),
            $zero
        );
        $canParse = $context->builder->and($len7, $prefix2y);
        $parseBb = $fn->appendBasicBlock('pw_nrh_parse');
        $doneBb = $fn->appendBasicBlock('pw_nrh_done');
        $context->builder->branchIf($canParse, $parseBb, $doneBb);

        $context->builder->positionAtEnd($parseBb);
        $digits = $context->builder->call(
            $context->lookupFunction('atoi'),
            $context->builder->gep($h, $i8->constInt(4, false))
        );
        $digitsI64 = $context->builder->sextOrBitCast($digits, $i64);
        $lt4 = $context->builder->icmp(Builder::INT_SLT, $digitsI64, $i64->constInt(4, false));
        $storeOld = $fn->appendBasicBlock('pw_nrh_store_old');
        $context->builder->branchIf($lt4, $doneBb, $storeOld);

        $context->builder->positionAtEnd($storeOld);
        $context->builder->store($digitsI64, $oldCostSlot);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $old = $context->builder->load($oldCostSlot);
        $eff = $context->builder->load($effCost);
        $diff = $context->builder->icmp(Builder::INT_NE, $old, $eff);
        $context->builder->returnValue($context->builder->select($diff, $one, $zero));
    }

    private static function buildInfoUnknown(Context $context, LlvmFunction $fn): Value
    {
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $opts = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $setStr = $context->lookupFunction('__hashtable__setStringKeyString');
        $setHt = $context->lookupFunction('__hashtable__setStringKeyHashtable');
        $context->builder->call($setStr, $ht, self::literalString($context, 'algoName'), self::literalString($context, 'unknown'));
        $context->builder->call($setHt, $ht, self::literalString($context, 'options'), $opts);

        return $ht;
    }

    private static function buildInfoBcrypt(Context $context, Value $costSlot): Value
    {
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $opts = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $setStr = $context->lookupFunction('__hashtable__setStringKeyString');
        $setLong = $context->lookupFunction('__hashtable__setStringKeyLong');
        $setHt = $context->lookupFunction('__hashtable__setStringKeyHashtable');
        $context->builder->call($setStr, $ht, self::literalString($context, 'algo'), self::literalString($context, '2y'));
        $context->builder->call($setStr, $ht, self::literalString($context, 'algoName'), self::literalString($context, 'bcrypt'));
        $context->builder->call($setLong, $opts, self::literalString($context, 'cost'), $context->builder->load($costSlot));
        $context->builder->call($setHt, $ht, self::literalString($context, 'options'), $opts);

        return $ht;
    }

    private static function emitBcryptEncodeSalt22(Context $context, LlvmFunction $fn, Value $src, Value $out): void
    {
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $itoa = self::cstr($context, self::BCRYPT_ITOA64);
        $iSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $oSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($i64->constInt(0, false), $iSlot);
        $context->builder->store($i64->constInt(0, false), $oSlot);

        $loopHead = $fn->appendBasicBlock('pw_enc_head');
        $loopBody = $fn->appendBasicBlock('pw_enc_body');
        $loopEnd = $fn->appendBasicBlock('pw_enc_end');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $o = $context->builder->load($oSlot);
        $cont = $context->builder->icmp(Builder::INT_SLT, $o, $i64->constInt(22, false));
        $context->builder->branchIf($cont, $loopBody, $loopEnd);

        $context->builder->positionAtEnd($loopBody);
        $i = $context->builder->load($iSlot);
        $c1 = $context->builder->load($context->builder->gep($src, $i));
        $i1 = $context->builder->add($i, $i64->constInt(1, false));
        $c2 = $context->builder->load($context->builder->gep($src, $i1));
        $i2 = $context->builder->add($i1, $i64->constInt(1, false));
        $o0 = $context->builder->load($oSlot);
        $idx0 = $context->builder->lshr($context->builder->zextOrBitCast($c1, $i32), $i32->constInt(2, false));
        $context->builder->store(
            $context->builder->load($context->builder->gep($itoa, $idx0)),
            $context->builder->gep($out, $o0)
        );
        $o1 = $context->builder->add($o0, $i64->constInt(1, false));
        $mix = $context->builder->or(
            $context->builder->shl(
                $context->builder->and($context->builder->zextOrBitCast($c1, $i32), $i32->constInt(0x03, false)),
                $i32->constInt(4, false)
            ),
            $context->builder->lshr($context->builder->zextOrBitCast($c2, $i32), $i32->constInt(4, false))
        );
        $context->builder->store(
            $context->builder->load($context->builder->gep($itoa, $mix)),
            $context->builder->gep($out, $o1)
        );
        $o2 = $context->builder->add($o1, $i64->constInt(1, false));
        $done22a = $context->builder->icmp(Builder::INT_SGE, $o2, $i64->constInt(22, false));
        $afterA = $fn->appendBasicBlock('pw_enc_after_a');
        $context->builder->branchIf($done22a, $loopEnd, $afterA);

        $context->builder->positionAtEnd($afterA);
        $c3 = $context->builder->load($context->builder->gep($src, $i2));
        $i3 = $context->builder->add($i2, $i64->constInt(1, false));
        $mix2 = $context->builder->or(
            $context->builder->shl(
                $context->builder->and($context->builder->zextOrBitCast($c2, $i32), $i32->constInt(0x0f, false)),
                $i32->constInt(2, false)
            ),
            $context->builder->lshr($context->builder->zextOrBitCast($c3, $i32), $i32->constInt(6, false))
        );
        $context->builder->store(
            $context->builder->load($context->builder->gep($itoa, $mix2)),
            $context->builder->gep($out, $o2)
        );
        $o3 = $context->builder->add($o2, $i64->constInt(1, false));
        $done22b = $context->builder->icmp(Builder::INT_SGE, $o3, $i64->constInt(22, false));
        $afterB = $fn->appendBasicBlock('pw_enc_after_b');
        $context->builder->branchIf($done22b, $loopEnd, $afterB);

        $context->builder->positionAtEnd($afterB);
        $mask3 = $context->builder->and($context->builder->zextOrBitCast($c3, $i32), $i32->constInt(0x3f, false));
        $context->builder->store(
            $context->builder->load($context->builder->gep($itoa, $mask3)),
            $context->builder->gep($out, $o3)
        );
        $context->builder->store($i3, $iSlot);
        $context->builder->store($context->builder->add($o3, $i64->constInt(1, false)), $oSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopEnd);
        $context->builder->store($i8->constInt(0, false), $context->builder->gep($out, $i64->constInt(22, false)));
    }

    private static function fillRandom(
        Context $context,
        LlvmFunction $fn,
        Value $buf,
        Value $len,
        BasicBlock $okBb,
        BasicBlock $failBb
    ): void {
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $zeroI32 = $i32->constInt(0, false);
        $doneSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($i64->constInt(0, false), $doneSlot);

        $loopHead = $fn->appendBasicBlock('pw_gr_head');
        $loopBody = $fn->appendBasicBlock('pw_gr_body');
        $fail = $fn->appendBasicBlock('pw_gr_fail');
        $advance = $fn->appendBasicBlock('pw_gr_advance');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $done = $context->builder->load($doneSlot);
        $need = $context->builder->icmp(Builder::INT_SLT, $done, $len);
        $context->builder->branchIf($need, $loopBody, $okBb);

        $context->builder->positionAtEnd($loopBody);
        $remain = $context->builder->sub($len, $done);
        $at = $context->builder->inBoundsGep($buf, $done);
        $ret = $context->builder->call(
            $context->lookupFunction('getrandom'),
            $at,
            $context->builder->truncOrBitCast($remain, $sizeT),
            $zeroI32
        );
        $retNeg = $context->builder->icmp(Builder::INT_SLT, $ret, $i64->constInt(0, false));
        $retZero = $context->builder->icmp(Builder::INT_EQ, $ret, $i64->constInt(0, false));
        $bad = $context->builder->or($retNeg, $retZero);
        $context->builder->branchIf($bad, $fail, $advance);

        $context->builder->positionAtEnd($fail);
        $context->builder->branch($failBb);

        $context->builder->positionAtEnd($advance);
        $context->builder->store($context->builder->add($done, $ret), $doneSlot);
        $context->builder->branch($loopHead);
    }

    private static function isValidSaltChar(Context $context, Value $c): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $cu = $context->builder->zextOrBitCast($c, $i32);
        $dot = $i32->constInt(ord('.'), false);
        $nine = $i32->constInt(ord('9'), false);
        $bigA = $i32->constInt(ord('A'), false);
        $bigZ = $i32->constInt(ord('Z'), false);
        $smA = $i32->constInt(ord('a'), false);
        $smZ = $i32->constInt(ord('z'), false);

        return $context->builder->or(
            $context->builder->and(
                $context->builder->icmp(Builder::INT_SGE, $cu, $dot),
                $context->builder->icmp(Builder::INT_SLE, $cu, $nine)
            ),
            $context->builder->or(
                $context->builder->and(
                    $context->builder->icmp(Builder::INT_SGE, $cu, $bigA),
                    $context->builder->icmp(Builder::INT_SLE, $cu, $bigZ)
                ),
                $context->builder->and(
                    $context->builder->icmp(Builder::INT_SGE, $cu, $smA),
                    $context->builder->icmp(Builder::INT_SLE, $cu, $smZ)
                )
            )
        );
    }

    private static function stringData(Context $context, Value $str): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->pointerCast(
            $context->builder->structGep($str, $map['value']),
            $context->getTypeFromString('int8*')
        );
    }

    private static function stringLen(Context $context, Value $str): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->load($context->builder->structGep($str, $map['length']));
    }

    private static function stringFromCstr(Context $context, Value $cstr): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $len = $context->builder->call($context->lookupFunction('strlen'), $cstr);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->sextOrBitCast($len, $i64),
            $cstr
        );
    }

    private static function literalString(Context $context, string $text): Value
    {
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(\strlen($text), false),
            self::cstr($context, $text)
        );
    }

    private static function cstr(Context $context, string $text): Value
    {
        $charPtr = $context->getTypeFromString('char*');

        return $context->builder->pointerCast($context->constantFromString($text), $charPtr);
    }

    private static function ensureLibc(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $charPtr = $context->getTypeFromString('char*');
        $sizeT = $context->getTypeFromString('size_t');
        $voidTy = $context->getTypeFromString('void');
        $i8pPtr = $i8p->pointerType(0);

        foreach ([
            ['crypt', $charPtr, [$charPtr, $charPtr]],
            ['snprintf', $i64, [$charPtr, $i64, $charPtr, $i32, $charPtr]],
            ['strcmp', $i32, [$charPtr, $charPtr]],
            ['strncmp', $i32, [$charPtr, $charPtr, $sizeT]],
            ['memcmp', $i32, [$charPtr, $charPtr, $sizeT]],
            ['strchr', $charPtr, [$charPtr, $i32]],
            ['strlen', $sizeT, [$charPtr]],
            ['atoi', $i32, [$charPtr]],
            ['sscanf', $i32, [$charPtr, $charPtr, $i64->pointerType(0)]],
            ['memcpy', $voidTy, [$i8p, $i8p, $sizeT]],
            ['getrandom', $i64, [$i8p, $sizeT, $i32]],
        ] as [$name, $ret, $params]) {
            self::ensureExternal($context, $name, $context->context->functionType($ret, false, ...$params));
        }
    }

    private static function ensureHelpers(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $voidTy = $context->getTypeFromString('void');
        $charPtr = $context->getTypeFromString('char*');
        $sizeT = $context->getTypeFromString('size_t');

        foreach ([
            ['__hashtable__alloc', $htPtr, []],
            ['__hashtable__setStringKeyString', $voidTy, [$htPtr, $strPtr, $strPtr]],
            ['__hashtable__setStringKeyLong', $voidTy, [$htPtr, $strPtr, $i64]],
            ['__hashtable__setStringKeyHashtable', $voidTy, [$htPtr, $strPtr, $htPtr]],
            ['__hashtable__setStringAt', $voidTy, [$htPtr, $sizeT, $strPtr]],
            ['__string__init', $strPtr, [$i64, $charPtr]],
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
}
