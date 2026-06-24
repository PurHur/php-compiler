<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringStreamCsv;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Value;

/** LLVM lowering for str_getcsv() via StringStrGetcsv / CsvJitHelper (#5288, #9444). */
final class JitStrGetcsv
{
    public static function invoke(
        Context $context,
        Value $inputStr,
        Value $separatorStr,
        Value $enclosureStr,
        Value $escapeStr,
    ): Value {
        StringStreamCsv::ensureLinked($context);
        $row = $context->builder->call(
            $context->lookupFunction('__compiler_str_getcsv'),
            $inputStr,
            $separatorStr,
            $enclosureStr,
            $escapeStr
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeHashtable'), $ptr, $row);

        return $ptr;
    }
}
