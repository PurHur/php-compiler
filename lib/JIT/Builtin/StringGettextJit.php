<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM lowering for ext/gettext builtins (php-src ext/gettext/gettext.c; #3449, #8625).
 *
 * libc gettext when declared; msgid/domain fallback mirrors {@see \PHPCompiler\ext\gettext\VmGettextNative}.
 */
final class StringGettextJit
{
    private const G_BOUND_DIR = 'phpc_gettext_bound_dir';

    private const G_BOUND_DIR_LEN = 'phpc_gettext_bound_dir_len';

    private const G_ACTIVE_DOMAIN = 'phpc_gettext_active_domain';

    private const G_ACTIVE_DOMAIN_LEN = 'phpc_gettext_active_domain_len';

    private const G_BOUND_CODESET = 'phpc_gettext_bound_codeset';

    private const G_BOUND_CODESET_LEN = 'phpc_gettext_bound_codeset_len';

    private const BOUND_DIR_CAP = 256;

    private const ACTIVE_DOMAIN_CAP = 64;

    private const BOUND_CODESET_CAP = 32;

    private static int $blockSerial = 0;

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_gettext');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $restore = self::captureInsertBlock($context);
        self::ensureGlobals($context);
        self::ensureLibc($context);

        self::implementIfMissing($context, '__compiler_gettext', self::emitGettext(...));
        self::implementIfMissing($context, '__compiler_dgettext', self::emitDgettext(...));
        self::implementIfMissing($context, '__compiler_dcgettext', self::emitDcgettext(...));
        self::implementIfMissing($context, '__compiler_dngettext', self::emitDngettext(...));
        self::implementIfMissing($context, '__compiler_dcngettext', self::emitDcngettext(...));
        self::implementIfMissing($context, '__compiler_bindtextdomain', self::emitBindtextdomain(...));
        self::implementIfMissing($context, '__compiler_textdomain', self::emitTextdomain(...));
        self::implementIfMissing($context, '__compiler_bind_textdomain_codeset', self::emitBindTextdomainCodeset(...));

        self::registerLinkedRuntime($context);
        self::restoreInsertBlock($context, $restore);
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
        $strPtr = $context->getTypeFromString('__string__*');
        $valPtr = $context->getTypeFromString('__value__*');
        $i64 = $context->getTypeFromString('int64');
        $voidTy = $context->getTypeFromString('void');

        $types = match ($name) {
            '__compiler_gettext' => $context->context->functionType($strPtr, false, $strPtr),
            '__compiler_dgettext' => $context->context->functionType($strPtr, false, $strPtr, $strPtr),
            '__compiler_dcgettext' => $context->context->functionType($strPtr, false, $strPtr, $strPtr, $i64),
            '__compiler_dngettext' => $context->context->functionType($strPtr, false, $strPtr, $strPtr, $strPtr, $i64),
            '__compiler_dcngettext' => $context->context->functionType($strPtr, false, $strPtr, $strPtr, $strPtr, $i64, $i64),
            '__compiler_bindtextdomain', '__compiler_bind_textdomain_codeset' => $context->context->functionType($voidTy, false, $strPtr, $strPtr, $valPtr),
            '__compiler_textdomain' => $context->context->functionType($voidTy, false, $strPtr, $valPtr),
            default => throw new \LogicException('unknown gettext runtime: '.$name),
        };

        try {
            return $context->lookupFunction($name);
        } catch (\Throwable) {
            $fn = $context->module->addFunction($name, $types);
            $context->registerFunction($name, $fn);

            return $fn;
        }
    }

    private static function emitGettext(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        $msgid = $fn->getParam(0);
        self::returnTranslateOrFallback(
            $context,
            $fn,
            self::copyString($context, $msgid),
            self::tryLibcTranslate($context, $fn, 'gettext', $msgid, null)
        );
    }

    private static function emitDgettext(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        $domain = $fn->getParam(0);
        $msgid = $fn->getParam(1);
        self::returnTranslateOrFallback(
            $context,
            $fn,
            self::copyString($context, $msgid),
            self::tryLibcTranslate($context, $fn, 'dgettext', $msgid, $domain)
        );
    }

    private static function emitDcgettext(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        $domain = $fn->getParam(0);
        $msgid = $fn->getParam(1);
        $category = $fn->getParam(2);
        self::returnTranslateOrFallback(
            $context,
            $fn,
            self::copyString($context, $msgid),
            self::tryLibcTranslateCategory($context, $fn, 'dcgettext', $msgid, $domain, $category)
        );
    }

    private static function emitDngettext(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        $domain = $fn->getParam(0);
        $msgid1 = $fn->getParam(1);
        $msgid2 = $fn->getParam(2);
        $count = $fn->getParam(3);
        $fallback = self::selectPluralFallback($context, $fn, $msgid1, $msgid2, $count);
        self::returnTranslateOrFallback(
            $context,
            $fn,
            $fallback,
            self::tryLibcPlural($context, $fn, 'dngettext', $domain, $msgid1, $msgid2, $count, null)
        );
    }

    private static function emitDcngettext(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        $domain = $fn->getParam(0);
        $msgid1 = $fn->getParam(1);
        $msgid2 = $fn->getParam(2);
        $count = $fn->getParam(3);
        $category = $fn->getParam(4);
        $fallback = self::selectPluralFallback($context, $fn, $msgid1, $msgid2, $count);
        self::returnTranslateOrFallback(
            $context,
            $fn,
            $fallback,
            self::tryLibcPlural($context, $fn, 'dcngettext', $domain, $msgid1, $msgid2, $count, $category)
        );
    }

    private static function returnTranslateOrFallback(
        Context $context,
        LlvmFunction $fn,
        Value $fallback,
        ?Value $translated
    ): void {
        if (null === $translated) {
            $context->builder->returnValue($fallback);

            return;
        }

        $id = (string) (++self::$blockSerial);
        $miss = $fn->appendBasicBlock('gettext_miss_'.$id);
        $ok = $fn->appendBasicBlock('gettext_ok_'.$id);
        $strPtrTy = $context->getTypeFromString('__string__*');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $translated, $strPtrTy->constNull());
        $context->builder->branchIf($isNull, $miss, $ok);
        $context->builder->positionAtEnd($ok);
        $context->builder->returnValue($translated);
        $context->builder->positionAtEnd($miss);
        $context->builder->returnValue($fallback);
    }

    private static function emitBindtextdomain(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        $domain = $fn->getParam(0);
        $dir = $fn->getParam(1);
        $out = $fn->getParam(2);

        $id = (string) (++self::$blockSerial);
        $hasDir = $fn->appendBasicBlock('bind_has_dir_'.$id);
        $query = $fn->appendBasicBlock('bind_query_'.$id);
        $done = $fn->appendBasicBlock('bind_done_'.$id);

        $strPtrTy = $context->getTypeFromString('__string__*');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $dir, $strPtrTy->constNull());
        $context->builder->branchIf($isNull, $query, $hasDir);

        $context->builder->positionAtEnd($hasDir);
        self::storeGlobalString($context, self::G_BOUND_DIR, self::G_BOUND_DIR_LEN, self::BOUND_DIR_CAP, $dir);
        $libc = self::tryLibcBindtextdomain($context, $fn, $domain, $dir);
        if (null !== $libc) {
            self::writeStringToValue($context, $out, $libc);
        } else {
            self::writeStringToValue($context, $out, self::copyString($context, $dir));
        }
        $context->builder->branch($done);

        $context->builder->positionAtEnd($query);
        self::writeOptionalGlobalStringToValue($context, $fn, $out, self::G_BOUND_DIR, self::G_BOUND_DIR_LEN);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
    }

    private static function emitTextdomain(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        $domain = $fn->getParam(0);
        $out = $fn->getParam(1);

        $id = (string) (++self::$blockSerial);
        $hasDomain = $fn->appendBasicBlock('td_has_domain_'.$id);
        $query = $fn->appendBasicBlock('td_query_'.$id);
        $done = $fn->appendBasicBlock('td_done_'.$id);

        $strPtrTy = $context->getTypeFromString('__string__*');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $domain, $strPtrTy->constNull());
        $context->builder->branchIf($isNull, $query, $hasDomain);

        $context->builder->positionAtEnd($hasDomain);
        self::storeGlobalString($context, self::G_ACTIVE_DOMAIN, self::G_ACTIVE_DOMAIN_LEN, self::ACTIVE_DOMAIN_CAP, $domain);
        $libc = self::tryLibcTextdomain($context, $fn, $domain);
        if (null !== $libc) {
            self::writeStringToValue($context, $out, $libc);
        } else {
            self::writeStringToValue($context, $out, self::copyString($context, $domain));
        }
        $context->builder->branch($done);

        $context->builder->positionAtEnd($query);
        self::writeOptionalGlobalStringToValue($context, $fn, $out, self::G_ACTIVE_DOMAIN, self::G_ACTIVE_DOMAIN_LEN);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
    }

    private static function emitBindTextdomainCodeset(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        $codeset = $fn->getParam(1);
        $out = $fn->getParam(2);

        $id = (string) (++self::$blockSerial);
        $hasCodeset = $fn->appendBasicBlock('btc_has_'.$id);
        $query = $fn->appendBasicBlock('btc_query_'.$id);
        $done = $fn->appendBasicBlock('btc_done_'.$id);

        $strPtrTy = $context->getTypeFromString('__string__*');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $codeset, $strPtrTy->constNull());
        $context->builder->branchIf($isNull, $query, $hasCodeset);

        $context->builder->positionAtEnd($hasCodeset);
        self::storeGlobalString($context, self::G_BOUND_CODESET, self::G_BOUND_CODESET_LEN, self::BOUND_CODESET_CAP, $codeset);
        self::writeStringToValue($context, $out, self::copyString($context, $codeset));
        $context->builder->branch($done);

        $context->builder->positionAtEnd($query);
        self::writeOptionalGlobalStringToValue($context, $fn, $out, self::G_BOUND_CODESET, self::G_BOUND_CODESET_LEN);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
    }

    private static function tryLibcTranslate(
        Context $context,
        LlvmFunction $fn,
        string $symbol,
        Value $msgid,
        ?Value $domain
    ): ?Value {
        try {
            $libcFn = $context->lookupFunction($symbol);
        } catch (\Throwable) {
            return null;
        }

        $id = (string) (++self::$blockSerial);
        $work = $fn->appendBasicBlock($symbol.'_work_'.$id);
        $miss = $fn->appendBasicBlock($symbol.'_miss_'.$id);
        $done = $fn->appendBasicBlock($symbol.'_done_'.$id);

        $msgCstr = self::copyStringObjectToCstr($context, $fn, $msgid);
        if (null !== $domain) {
            $domainCstr = self::copyStringObjectToCstr($context, $fn, $domain);
            $raw = $context->builder->call($libcFn, $domainCstr, $msgCstr);
        } else {
            $raw = $context->builder->call($libcFn, $msgCstr);
        }

        $i8p = $context->getTypeFromString('int8*');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $raw, $i8p->constNull());
        $context->builder->branchIf($isNull, $miss, $work);

        $context->builder->positionAtEnd($work);
        $str = self::cstrToString($context, $raw);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($miss);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $strPtrTy = $context->getTypeFromString('__string__*');
        $phi = $context->builder->phi($strPtrTy);
        $phi->addIncoming($str, $work);
        $phi->addIncoming($strPtrTy->constNull(), $miss);

        return $phi;
    }

    private static function tryLibcTranslateCategory(
        Context $context,
        LlvmFunction $fn,
        string $symbol,
        Value $msgid,
        Value $domain,
        Value $category
    ): ?Value {
        try {
            $libcFn = $context->lookupFunction($symbol);
        } catch (\Throwable) {
            return null;
        }

        $id = (string) (++self::$blockSerial);
        $work = $fn->appendBasicBlock($symbol.'_work_'.$id);
        $miss = $fn->appendBasicBlock($symbol.'_miss_'.$id);
        $done = $fn->appendBasicBlock($symbol.'_done_'.$id);

        $domainCstr = self::copyStringObjectToCstr($context, $fn, $domain);
        $msgCstr = self::copyStringObjectToCstr($context, $fn, $msgid);
        $i32 = $context->getTypeFromString('int32');
        $catI32 = $category->typeOf() === $i32
            ? $category
            : $context->builder->trunc($category, $i32);
        $raw = $context->builder->call($libcFn, $domainCstr, $msgCstr, $catI32);

        $i8p = $context->getTypeFromString('int8*');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $raw, $i8p->constNull());
        $context->builder->branchIf($isNull, $miss, $work);

        $context->builder->positionAtEnd($work);
        $str = self::cstrToString($context, $raw);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($miss);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $strPtrTy = $context->getTypeFromString('__string__*');
        $phi = $context->builder->phi($strPtrTy);
        $phi->addIncoming($str, $work);
        $phi->addIncoming($strPtrTy->constNull(), $miss);

        return $phi;
    }

    private static function tryLibcPlural(
        Context $context,
        LlvmFunction $fn,
        string $symbol,
        Value $domain,
        Value $msgid1,
        Value $msgid2,
        Value $count,
        ?Value $category
    ): ?Value {
        try {
            $libcFn = $context->lookupFunction($symbol);
        } catch (\Throwable) {
            return null;
        }

        $id = (string) (++self::$blockSerial);
        $work = $fn->appendBasicBlock($symbol.'_work_'.$id);
        $miss = $fn->appendBasicBlock($symbol.'_miss_'.$id);
        $done = $fn->appendBasicBlock($symbol.'_done_'.$id);

        $domainCstr = self::copyStringObjectToCstr($context, $fn, $domain);
        $msg1Cstr = self::copyStringObjectToCstr($context, $fn, $msgid1);
        $msg2Cstr = self::copyStringObjectToCstr($context, $fn, $msgid2);
        $i64 = $context->getTypeFromString('int64');
        $ulong = $context->getTypeFromString('unsigned long');
        $n = $count->typeOf() === $ulong
            ? $count
            : $context->builder->zExt($count, $ulong);

        if (null !== $category) {
            $i32 = $context->getTypeFromString('int32');
            $catI32 = $category->typeOf() === $i32
                ? $category
                : $context->builder->trunc($category, $i32);
            $raw = $context->builder->call($libcFn, $domainCstr, $msg1Cstr, $msg2Cstr, $n, $catI32);
        } else {
            $raw = $context->builder->call($libcFn, $domainCstr, $msg1Cstr, $msg2Cstr, $n);
        }

        $i8p = $context->getTypeFromString('int8*');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $raw, $i8p->constNull());
        $context->builder->branchIf($isNull, $miss, $work);

        $context->builder->positionAtEnd($work);
        $str = self::cstrToString($context, $raw);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($miss);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $strPtrTy = $context->getTypeFromString('__string__*');
        $phi = $context->builder->phi($strPtrTy);
        $phi->addIncoming($str, $work);
        $phi->addIncoming($strPtrTy->constNull(), $miss);

        return $phi;
    }

    private static function tryLibcBindtextdomain(Context $context, LlvmFunction $fn, Value $domain, Value $dir): ?Value
    {
        try {
            $libcFn = $context->lookupFunction('bindtextdomain');
        } catch (\Throwable) {
            return null;
        }

        $domainCstr = self::copyStringObjectToCstr($context, $fn, $domain);
        $dirCstr = self::copyStringObjectToCstr($context, $fn, $dir);
        $raw = $context->builder->call($libcFn, $domainCstr, $dirCstr);
        $i8p = $context->getTypeFromString('int8*');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $raw, $i8p->constNull());
        $id = (string) (++self::$blockSerial);
        $miss = $fn->appendBasicBlock('bindtextdomain_miss_'.$id);
        $ok = $fn->appendBasicBlock('bindtextdomain_ok_'.$id);
        $done = $fn->appendBasicBlock('bindtextdomain_done_'.$id);
        $context->builder->branchIf($isNull, $miss, $ok);
        $context->builder->positionAtEnd($ok);
        $str = self::cstrToString($context, $raw);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($miss);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
        $strPtrTy = $context->getTypeFromString('__string__*');
        $phi = $context->builder->phi($strPtrTy);
        $phi->addIncoming($str, $ok);
        $phi->addIncoming($strPtrTy->constNull(), $miss);

        return $phi;
    }

    private static function tryLibcTextdomain(Context $context, LlvmFunction $fn, Value $domain): ?Value
    {
        try {
            $libcFn = $context->lookupFunction('textdomain');
        } catch (\Throwable) {
            return null;
        }

        $domainCstr = self::copyStringObjectToCstr($context, $fn, $domain);
        $raw = $context->builder->call($libcFn, $domainCstr);
        $i8p = $context->getTypeFromString('int8*');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $raw, $i8p->constNull());
        $id = (string) (++self::$blockSerial);
        $miss = $fn->appendBasicBlock('textdomain_miss_'.$id);
        $ok = $fn->appendBasicBlock('textdomain_ok_'.$id);
        $done = $fn->appendBasicBlock('textdomain_done_'.$id);
        $context->builder->branchIf($isNull, $miss, $ok);
        $context->builder->positionAtEnd($ok);
        $str = self::cstrToString($context, $raw);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($miss);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
        $strPtrTy = $context->getTypeFromString('__string__*');
        $phi = $context->builder->phi($strPtrTy);
        $phi->addIncoming($str, $ok);
        $phi->addIncoming($strPtrTy->constNull(), $miss);

        return $phi;
    }

    private static function selectPluralFallback(
        Context $context,
        LlvmFunction $fn,
        Value $msgid1,
        Value $msgid2,
        Value $count
    ): Value {
        $id = (string) (++self::$blockSerial);
        $one = $fn->appendBasicBlock('plural_one_'.$id);
        $many = $fn->appendBasicBlock('plural_many_'.$id);
        $done = $fn->appendBasicBlock('plural_done_'.$id);
        $i64 = $context->getTypeFromString('int64');
        $oneConst = $i64->constInt(1, false);
        $isOne = $context->builder->icmp(Builder::INT_EQ, $count, $oneConst);
        $context->builder->branchIf($isOne, $one, $many);
        $context->builder->positionAtEnd($one);
        $first = self::copyString($context, $msgid1);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($many);
        $second = self::copyString($context, $msgid2);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
        $strPtrTy = $context->getTypeFromString('__string__*');
        $phi = $context->builder->phi($strPtrTy);
        $phi->addIncoming($first, $one);
        $phi->addIncoming($second, $many);

        return $phi;
    }

    private static function writeStringToValue(Context $context, Value $out, Value $str): void
    {
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $out,
            $str
        );
    }

    private static function writeOptionalGlobalStringToValue(
        Context $context,
        LlvmFunction $fn,
        Value $out,
        string $bufGlobal,
        string $lenGlobal
    ): void {
        $id = (string) (++self::$blockSerial);
        $fail = $fn->appendBasicBlock('gettext_false_'.$id);
        $ok = $fn->appendBasicBlock('gettext_str_'.$id);
        $done = $fn->appendBasicBlock('gettext_out_done_'.$id);
        $i8 = $context->getTypeFromString('int8');
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $storedLen = $context->builder->load($context->module->getGlobalVariable($lenGlobal));
        $hasStored = $context->builder->icmp(Builder::INT_NE, $storedLen, $zero);
        $context->builder->branchIf($hasStored, $ok, $fail);

        $context->builder->positionAtEnd($fail);
        $valMap = $context->structFieldMap['__value__'];
        $context->builder->store(
            $i8->constInt(Variable::TYPE_NATIVE_BOOL, false),
            $context->builder->structGep($out, $valMap['type'])
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($ok);
        $str = self::globalBufToString($context, $bufGlobal);
        self::writeStringToValue($context, $out, $str);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    private static function copyString(Context $context, Value $subject): Value
    {
        $map = $context->structFieldMap['__string__'];
        $slen = $context->builder->load($context->builder->structGep($subject, $map['length']));
        $sdata = $context->builder->structGep($subject, $map['value']);

        return $context->builder->call($context->lookupFunction('__string__init'), $slen, $sdata);
    }

    private static function cstrToString(Context $context, Value $cstr): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $len = $context->builder->call($context->lookupFunction('strlen'), $cstr);
        $lenI64 = $len->typeOf() === $i64 ? $len : $context->builder->zExt($len, $i64);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $lenI64,
            $cstr
        );
    }

    private static function globalBufToString(Context $context, string $bufGlobal): Value
    {
        $i8p = $context->getTypeFromString('int8*');
        $buf = $context->module->getGlobalVariable($bufGlobal);
        $cstr = $context->builder->pointerCast($buf, $i8p);

        return self::cstrToString($context, $cstr);
    }

    private static function storeGlobalString(
        Context $context,
        string $bufGlobal,
        string $lenGlobal,
        int $cap,
        Value $str
    ): void {
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load($context->builder->structGep($str, $map['length']));
        $bytes = $context->builder->structGep($str, $map['value']);
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $buf = $context->module->getGlobalVariable($bufGlobal);
        $lenG = $context->module->getGlobalVariable($lenGlobal);
        $max = $sizeT->constInt($cap - 1, false);
        $copyLen = $context->builder->select(
            $context->builder->icmp(Builder::INT_UGT, $len, $max),
            $max,
            $len
        );
        $context->builder->store($copyLen, $lenG);
        $dest = $context->builder->pointerCast($buf, $i8p);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->bytePtr($dest),
            $context->bytePtr($bytes),
            $copyLen
        );
        $end = $context->builder->gep($dest, $copyLen);
        $context->builder->store($i8->constInt(0, false), $end);
    }

    private static function copyStringObjectToCstr(Context $context, LlvmFunction $fn, Value $strObj): Value
    {
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load($context->builder->structGep($strObj, $map['length']));
        $bytes = $context->builder->structGep($strObj, $map['value']);
        $sizeT = $context->getTypeFromString('size_t');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $allocSize = $context->builder->add($len, $sizeT->constInt(1, false));
        $raw = $context->builder->call($context->lookupFunction('malloc'), $allocSize);
        $out = $context->builder->pointerCast($raw, $i8p);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->bytePtr($out),
            $context->bytePtr($bytes),
            $len
        );
        $end = $context->builder->gep($out, $len);
        $context->builder->store($i8->constInt(0, false), $end);

        return $out;
    }

    private static function ensureGlobals(Context $context): void
    {
        $i8 = $context->getTypeFromString('int8');
        $sizeT = $context->getTypeFromString('size_t');
        self::ensureGlobalArray($context, self::G_BOUND_DIR, $i8, self::BOUND_DIR_CAP);
        self::ensureGlobalScalar($context, self::G_BOUND_DIR_LEN, $sizeT);
        self::ensureGlobalArray($context, self::G_ACTIVE_DOMAIN, $i8, self::ACTIVE_DOMAIN_CAP);
        self::ensureGlobalScalar($context, self::G_ACTIVE_DOMAIN_LEN, $sizeT);
        self::ensureGlobalArray($context, self::G_BOUND_CODESET, $i8, self::BOUND_CODESET_CAP);
        self::ensureGlobalScalar($context, self::G_BOUND_CODESET_LEN, $sizeT);
    }

    private static function ensureGlobalArray(Context $context, string $name, $elemTy, int $count): void
    {
        if (null !== $context->module->getGlobalVariable($name)) {
            return;
        }
        $arrTy = $elemTy->arrayType($count);
        $context->module->addGlobalVariable($name, $arrTy, true);
    }

    private static function ensureGlobalScalar(Context $context, string $name, $ty): void
    {
        if (null !== $context->module->getGlobalVariable($name)) {
            return;
        }
        $context->module->addGlobalVariable($name, $ty, true);
    }

    private static function ensureLibc(Context $context): void
    {
        $decls = [
            ['gettext', ['int8*'], 'int8*'],
            ['dgettext', ['int8*', 'int8*'], 'int8*'],
            ['dcgettext', ['int8*', 'int8*', 'int32'], 'int8*'],
            ['dngettext', ['int8*', 'int8*', 'int8*', 'unsigned long'], 'int8*'],
            ['dcngettext', ['int8*', 'int8*', 'int8*', 'unsigned long', 'int32'], 'int8*'],
            ['bindtextdomain', ['int8*', 'int8*'], 'int8*'],
            ['textdomain', ['int8*'], 'int8*'],
            ['strlen', ['int8*'], 'size_t'],
            ['memcpy', ['int8*', 'int8*', 'size_t'], 'void'],
            ['malloc', ['size_t'], 'int8*'],
        ];
        foreach ($decls as [$name, $params, $ret]) {
            if (null !== $context->module->getNamedFunction($name)) {
                continue;
            }
            $paramTypes = array_map(static fn (string $t) => $context->getTypeFromString($t), $params);
            $retTy = $context->getTypeFromString($ret);
            $ft = $context->context->functionType($retTy, false, ...$paramTypes);
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach ([
            '__compiler_gettext',
            '__compiler_dgettext',
            '__compiler_dcgettext',
            '__compiler_dngettext',
            '__compiler_dcngettext',
            '__compiler_bindtextdomain',
            '__compiler_textdomain',
            '__compiler_bind_textdomain_codeset',
        ] as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null !== $fn) {
                $context->registerFunction($name, $fn);
            }
        }
    }

    private static function captureInsertBlock(Context $context): mixed
    {
        return $context->builder->getInsertBlock();
    }

    private static function restoreInsertBlock(Context $context, mixed $block): void
    {
        if (null !== $block) {
            $context->builder->positionAtEnd($block);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
