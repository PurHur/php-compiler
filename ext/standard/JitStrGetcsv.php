<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringStrGetcsv;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Value;

/** LLVM lowering for str_getcsv() via StringStrGetcsv / CsvJitHelper (#5288, #9444, #27069). */
final class JitStrGetcsv
{
    public static function invoke(
        Context $context,
        Value $inputStr,
        Value $separatorStr,
        Value $enclosureStr,
        Value $escapeStr,
    ): Value {
        // Do not route through StreamCsv aggregate ensureLinked — that also NestedJITs
        // fgetcsv/VmFs and SIGSEGVs thin AOT after c:main_before_php (#27069; peer wordwrap #26904).
        StringStrGetcsv::ensureLinked($context);
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
