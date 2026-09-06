<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * parse_url($url, $component) via leaf ParseUrlJitHelper methods (#36382).
 *
 * Do not NestedJIT ParseUrlJitHelper componentString — nesting into pathOf SEGVs
 * under thin AOT for runtime URL strings (direct pathOf is fine).
 */
final class ParseUrlComponentLlvm
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
        $entry = $fn->appendBasicBlock('puc_bridge_entry');
        $nullOutBb = $fn->appendBasicBlock('puc_null_out');
        $bodyBb = $fn->appendBasicBlock('puc_body');
        $context->builder->positionAtEnd($entry);
        $url = $fn->getParam(0);
        $component = $fn->getParam(1);
        $out = $fn->getParam(2);
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
        $i64 = $context->getTypeFromString('int64');
        $compI32 = $context->builder->trunc($component, $i32);
        $doneBb = BasicBlockHelper::append($context, 'puc_done');

        // PHP_URL_*: SCHEME=0 HOST=1 PORT=2 USER=3 PASS=4 PATH=5 QUERY=6 FRAGMENT=7
        $arms = [
            0 => self::SCHEME,
            1 => self::HOST,
            5 => self::PATH,
            6 => self::QUERY,
            7 => self::FRAGMENT,
        ];
        $next = $bodyBb;
        foreach ($arms as $comp => $logical) {
            $context->builder->positionAtEnd($next);
            $matchBb = BasicBlockHelper::append($context, 'puc_comp_'.$comp);
            $contBb = BasicBlockHelper::append($context, 'puc_cont_'.$comp);
            $context->builder->branchIf(
                $context->builder->icmp(Builder::INT_EQ, $compI32, $i32->constInt($comp, false)),
                $matchBb,
                $contBb
            );
            $context->builder->positionAtEnd($matchBb);
            self::emitNonEmptyString($context, $url, $out, $logical, $doneBb);
            $next = $contBb;
        }

        $context->builder->positionAtEnd($next);
        $portBb = BasicBlockHelper::append($context, 'puc_port');
        $afterPort = BasicBlockHelper::append($context, 'puc_after_port');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $compI32, $i32->constInt(2, false)),
            $portBb,
            $afterPort
        );
        $context->builder->positionAtEnd($portBb);
        $port = JitNestedHelperCoerce::extractLongFromHelperResult(
            $context,
            JitNestedHelperCoerce::callHelper($context, self::helper($context, self::PORT), [$url]),
            $i64
        );
        $portNull = BasicBlockHelper::append($context, 'puc_port_null');
        $portInt = BasicBlockHelper::append($context, 'puc_port_int');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLT, $port, $i64->constInt(0, true)),
            $portNull,
            $portInt
        );
        $context->builder->positionAtEnd($portNull);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $out);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($portInt);
        $context->builder->call($context->lookupFunction('__value__writeLong'), $out, $port);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($afterPort);
        $userBb = BasicBlockHelper::append($context, 'puc_user');
        $afterUser = BasicBlockHelper::append($context, 'puc_after_user');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $compI32, $i32->constInt(3, false)),
            $userBb,
            $afterUser
        );
        $context->builder->positionAtEnd($userBb);
        self::emitPresentString($context, $url, $out, self::HAS_USER, self::USER, $doneBb);

        $context->builder->positionAtEnd($afterUser);
        $passBb = BasicBlockHelper::append($context, 'puc_pass');
        $fallbackBb = BasicBlockHelper::append($context, 'puc_fallback_null');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $compI32, $i32->constInt(4, false)),
            $passBb,
            $fallbackBb
        );
        $context->builder->positionAtEnd($passBb);
        self::emitPresentString($context, $url, $out, self::HAS_PASS, self::PASS, $doneBb);

        $context->builder->positionAtEnd($fallbackBb);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $out);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnVoid();
    }

    private static function emitNonEmptyString(
        Context $context,
        Value $url,
        Value $out,
        string $logical,
        BasicBlock $doneBb
    ): void {
        $i64 = $context->getTypeFromString('int64');
        $str = JitNestedHelperCoerce::extractStringPtrFromHelperResult(
            $context,
            JitNestedHelperCoerce::callHelper($context, self::helper($context, $logical), [$url])
        );
        $len = $context->builder->call($context->lookupFunction('__string__strlen'), $str);
        $nullBb = BasicBlockHelper::append($context, 'puc_str_null');
        $okBb = BasicBlockHelper::append($context, 'puc_str_ok');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $len, $i64->constInt(0, false)),
            $nullBb,
            $okBb
        );
        $context->builder->positionAtEnd($nullBb);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $out);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($okBb);
        $context->builder->call($context->lookupFunction('__value__writeString'), $out, $str);
        $context->builder->branch($doneBb);
    }

    private static function emitPresentString(
        Context $context,
        Value $url,
        Value $out,
        string $hasLogical,
        string $valueLogical,
        BasicBlock $doneBb
    ): void {
        $has = JitNestedHelperCoerce::extractBoolFromHelperResult(
            $context,
            JitNestedHelperCoerce::callHelper($context, self::helper($context, $hasLogical), [$url])
        );
        $nullBb = BasicBlockHelper::append($context, 'puc_present_null');
        $okBb = BasicBlockHelper::append($context, 'puc_present_ok');
        $context->builder->branchIf($has, $okBb, $nullBb);
        $context->builder->positionAtEnd($nullBb);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $out);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($okBb);
        $str = JitNestedHelperCoerce::extractStringPtrFromHelperResult(
            $context,
            JitNestedHelperCoerce::callHelper($context, self::helper($context, $valueLogical), [$url])
        );
        $context->builder->call($context->lookupFunction('__value__writeString'), $out, $str);
        $context->builder->branch($doneBb);
    }

    private static function helper(Context $context, string $logical): LlvmFunction
    {
        return JitVmHelperLink::lookupCompiled($context, $logical, '#22861');
    }
}
