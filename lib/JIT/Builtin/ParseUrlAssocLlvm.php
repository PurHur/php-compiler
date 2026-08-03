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
 * Assoc parse_url HT via parseUrlComponent + lastString/lastInt (#27078).
 *
 * NestedJIT array returns / static string field copies are unsafe under thin AOT.
 */
final class ParseUrlAssocLlvm
{
    private const COMPONENT = 'PHPCompiler\\ext\\standard\\ParseUrlJitHelper::parseUrlComponent';

    private const LAST_STRING = 'PHPCompiler\\ext\\standard\\ParseUrlJitHelper::lastString';

    private const LAST_INT = 'PHPCompiler\\ext\\standard\\ParseUrlJitHelper::lastInt';

    private const TAG_FALSE = 0;

    private const TAG_STRING = 2;

    private const TAG_INT = 3;

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
        $i32 = $context->getTypeFromString('int32');
        $schemeTag = $context->builder->trunc(
            JitNestedHelperCoerce::callHelper(
                $context,
                self::helper($context, self::COMPONENT),
                [$url, $i32->constInt(\PHP_URL_SCHEME, false)]
            ),
            $i32
        );
        $falseBb = BasicBlockHelper::append($context, 'pua_false');
        $storeBb = BasicBlockHelper::append($context, 'pua_store');
        $doneBb = BasicBlockHelper::append($context, 'pua_done');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $schemeTag, $i32->constInt(self::TAG_FALSE, false)),
            $falseBb,
            $storeBb
        );
        $context->builder->positionAtEnd($falseBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $out,
            $i32->constInt(0, false)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($storeBb);
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        self::storeString($context, $fn, $ht, 'scheme', $schemeTag);
        foreach (
            [
                ['host', \PHP_URL_HOST, false],
                ['port', \PHP_URL_PORT, true],
                ['user', \PHP_URL_USER, false],
                ['pass', \PHP_URL_PASS, false],
                ['path', \PHP_URL_PATH, false],
                ['query', \PHP_URL_QUERY, false],
                ['fragment', \PHP_URL_FRAGMENT, false],
            ] as [$key, $comp, $wantInt]
        ) {
            $tag = $context->builder->trunc(
                JitNestedHelperCoerce::callHelper(
                    $context,
                    self::helper($context, self::COMPONENT),
                    [$url, $i32->constInt($comp, false)]
                ),
                $i32
            );
            if ($wantInt) {
                self::storeLong($context, $fn, $ht, $key, $tag);
            } else {
                self::storeString($context, $fn, $ht, $key, $tag);
            }
        }
        $context->builder->call($context->lookupFunction('__value__writeHashtable'), $out, $ht);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnVoid();
    }

    private static function storeString(
        Context $context,
        LlvmFunction $fn,
        Value $ht,
        string $key,
        Value $tagI32
    ): void {
        $i32 = $context->getTypeFromString('int32');
        $setBb = $fn->appendBasicBlock('pua_str_'.$key);
        $skipBb = $fn->appendBasicBlock('pua_str_skip_'.$key);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $tagI32, $i32->constInt(self::TAG_STRING, false)),
            $setBb,
            $skipBb
        );
        $context->builder->positionAtEnd($setBb);
        $strRaw = JitNestedHelperCoerce::callHelper($context, self::helper($context, self::LAST_STRING), []);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyString'),
            $ht,
            $context->builder->load($context->constantStringFromString($key)),
            JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $strRaw)
        );
        $context->builder->branch($skipBb);
        $context->builder->positionAtEnd($skipBb);
    }

    private static function storeLong(
        Context $context,
        LlvmFunction $fn,
        Value $ht,
        string $key,
        Value $tagI32
    ): void {
        $i32 = $context->getTypeFromString('int32');
        $setBb = $fn->appendBasicBlock('pua_long_'.$key);
        $skipBb = $fn->appendBasicBlock('pua_long_skip_'.$key);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $tagI32, $i32->constInt(self::TAG_INT, false)),
            $setBb,
            $skipBb
        );
        $context->builder->positionAtEnd($setBb);
        $intRaw = JitNestedHelperCoerce::callHelper($context, self::helper($context, self::LAST_INT), []);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyLong'),
            $ht,
            $context->builder->load($context->constantStringFromString($key)),
            JitNestedHelperCoerce::scalarToI64($context, $intRaw, $intRaw->typeOf())
        );
        $context->builder->branch($skipBb);
        $context->builder->positionAtEnd($skipBb);
    }

    private static function helper(Context $context, string $logical): LlvmFunction
    {
        return JitVmHelperLink::lookupCompiled($context, $logical, '#22861');
    }
}
