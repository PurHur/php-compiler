<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** LLVM JIT helper for html_entity_decode() — ENT_HTML5 dispatches to helper (#4130). */
final class JitHtmlEntityDecode
{
    public static function decode(Context $context, Value $strPtr, Value $flags): Value
    {
        return \PHPCompiler\JIT\Builtin\HtmlEntityDecodeJit::decode($context, $strPtr, $flags);
    }

    public static function decodeWithEncoding(
        Context $context,
        Value $strPtr,
        Value $flags,
        Value $encodingPtr
    ): Value {
        return \PHPCompiler\JIT\Builtin\HtmlEntityDecodeJit::decodeWithEncoding(
            $context,
            $strPtr,
            $flags,
            $encodingPtr
        );
    }
}
