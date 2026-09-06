<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * Assoc parse_url HT via leaf ParseUrlJitHelper methods (#27078, #33226, #36382).
 *
 * Do not NestedJIT-call ParseUrlJitHelper componentString / parseUrlComponent dispatchers:
 * those nest into pathOf and SEGV under thin AOT (stack / epilogue) for runtime URL strings —
 * while direct pathOf is fine (#36382).
 *
 * NestedJIT array returns / static string field copies are unsafe under thin AOT.
 * Emitted under {@see ParseUrlRuntime} {@see BasicBlockHelper::scopeLoweringToFunction} (#27211).
 */
final class ParseUrlAssocLlvm
{
    private const SCHEME = 'PHPCompiler\\ext\\standard\\ParseUrlJitHelper::schemeOf';

    private const HOST = 'PHPCompiler\\ext\\standard\\ParseUrlJitHelper::hostOf';

    private const USER = 'PHPCompiler\\ext\\standard\\ParseUrlJitHelper::userOf';

    private const PASS = 'PHPCompiler\\ext\\standard\\ParseUrlJitHelper::passOf';

    private const PATH = 'PHPCompiler\\ext\\standard\\ParseUrlJitHelper::pathOf';

    private const QUERY = 'PHPCompiler\\ext\\standard\\ParseUrlJitHelper::queryOf';

    private const FRAGMENT = 'PHPCompiler\\ext\\standard\\ParseUrlJitHelper::fragmentOf';

    private const PORT = 'PHPCompiler\\ext\\standard\\ParseUrlJitHelper::portOf';

    private const HAS_USER = 'PHPCompiler\\ext\\standard\\ParseUrlJitHelper::hasUser';

    private const HAS_PASS = 'PHPCompiler\\ext\\standard\\ParseUrlJitHelper::hasPass';

    public static function implement(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('pua_bridge_entry');
        $nullOutBb = $fn->appendBasicBlock('pua_null_out');
        $bodyBb = $fn->appendBasicBlock('pua_body');
        $context->builder->positionAtEnd($entry);
        $url = $fn->getParam(0);
        $out = $fn->getParam(1);
        $valuePtr = $context->getTypeFromString('__value__*');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $out, $valuePtr->constNull()),
            $nullOutBb,
            $bodyBb
        );
        $context->builder->positionAtEnd($nullOutBb);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($bodyBb);
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        self::storeNonEmptyString($context, $fn, $ht, $url, 'scheme', self::SCHEME);
        self::storeNonEmptyString($context, $fn, $ht, $url, 'host', self::HOST);
        self::storePort($context, $fn, $ht, $url);
        self::storePresentUserPass($context, $fn, $ht, $url, 'user', self::HAS_USER, self::USER);
        self::storePresentUserPass($context, $fn, $ht, $url, 'pass', self::HAS_PASS, self::PASS);
        self::storeNonEmptyString($context, $fn, $ht, $url, 'path', self::PATH);
        self::storeNonEmptyString($context, $fn, $ht, $url, 'query', self::QUERY);
        self::storeNonEmptyString($context, $fn, $ht, $url, 'fragment', self::FRAGMENT);
        $context->builder->call($context->lookupFunction('__value__writeHashtable'), $out, $ht);
        $context->builder->returnVoid();
    }

    private static function storeNonEmptyString(
        Context $context,
        LlvmFunction $fn,
        Value $ht,
        Value $url,
        string $key,
        string $logical
    ): void {
        $str = JitNestedHelperCoerce::extractStringPtrFromHelperResult(
            $context,
            JitNestedHelperCoerce::callHelper($context, self::helper($context, $logical), [$url])
        );
        $len = $context->builder->call($context->lookupFunction('__string__strlen'), $str);
        $i64 = $context->getTypeFromString('int64');
        $setBb = $fn->appendBasicBlock('pua_str_'.$key);
        $skipBb = $fn->appendBasicBlock('pua_str_skip_'.$key);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $len, $i64->constInt(0, false)),
            $skipBb,
            $setBb
        );
        $context->builder->positionAtEnd($setBb);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyString'),
            $ht,
            $context->builder->load($context->constantStringFromString($key)),
            $str
        );
        $context->builder->branch($skipBb);
        $context->builder->positionAtEnd($skipBb);
    }

    private static function storePort(Context $context, LlvmFunction $fn, Value $ht, Value $url): void
    {
        $i64 = $context->getTypeFromString('int64');
        $port = JitNestedHelperCoerce::extractLongFromHelperResult(
            $context,
            JitNestedHelperCoerce::callHelper($context, self::helper($context, self::PORT), [$url]),
            $i64
        );
        $setBb = $fn->appendBasicBlock('pua_long_port');
        $skipBb = $fn->appendBasicBlock('pua_long_skip_port');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLT, $port, $i64->constInt(0, true)),
            $skipBb,
            $setBb
        );
        $context->builder->positionAtEnd($setBb);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyLong'),
            $ht,
            $context->builder->load($context->constantStringFromString('port')),
            $port
        );
        $context->builder->branch($skipBb);
        $context->builder->positionAtEnd($skipBb);
    }

    private static function storePresentUserPass(
        Context $context,
        LlvmFunction $fn,
        Value $ht,
        Value $url,
        string $key,
        string $hasLogical,
        string $valueLogical
    ): void {
        $has = JitNestedHelperCoerce::extractBoolFromHelperResult(
            $context,
            JitNestedHelperCoerce::callHelper($context, self::helper($context, $hasLogical), [$url])
        );
        $setBb = $fn->appendBasicBlock('pua_str_'.$key);
        $skipBb = $fn->appendBasicBlock('pua_str_skip_'.$key);
        $context->builder->branchIf($has, $setBb, $skipBb);
        $context->builder->positionAtEnd($setBb);
        $str = JitNestedHelperCoerce::extractStringPtrFromHelperResult(
            $context,
            JitNestedHelperCoerce::callHelper($context, self::helper($context, $valueLogical), [$url])
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyString'),
            $ht,
            $context->builder->load($context->constantStringFromString($key)),
            $str
        );
        $context->builder->branch($skipBb);
        $context->builder->positionAtEnd($skipBb);
    }

    private static function helper(Context $context, string $logical): LlvmFunction
    {
        return JitVmHelperLink::lookupCompiled($context, $logical, '#22861');
    }
}
