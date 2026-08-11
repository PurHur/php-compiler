<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\CliArgvRuntime;
use PHPCompiler\JIT\Builtin\Getopt;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** LLVM lowering for getopt() (#3251 phase 2). */
final class JitGetopt
{
    /**
     * @param list<string> $longOptions compile-time long option specs
     */
    public static function invoke(
        Context $context,
        JITVariable $shortArg,
        array $longOptions
    ): Value {
        Getopt::ensureLinked($context);
        CliArgvRuntime::ensureLinked($context);

        // Z_PARAM_STR — caller strict_types → TypeError on null; soft-null outside (#30358).
        $shortStr = JitStringBuiltinArg::lowerStrictOrCoercible(
            $context,
            $shortArg,
            'getopt',
            0,
            'short_options',
            'string',
            null,
            false
        );
        $longHt = self::longOptionsHashtable($context, $longOptions);
        $argvHt = CliArgvRuntime::buildArgvHashtable($context);

        return $context->builder->call(Getopt::helperFunction($context), $shortStr, $longHt, $argvHt);
    }

    /**
     * @param list<string> $longOptions
     */
    private static function longOptionsHashtable(Context $context, array $longOptions): Value
    {
        if ([] === $longOptions) {
            return HashTableHelper::alloc($context);
        }
        $table = new HashTable();
        $idx = 0;
        foreach ($longOptions as $spec) {
            $cell = new Variable();
            $cell->string((string) $spec);
            $table->updateIndex($idx, $cell);
            ++$idx;
        }

        return $context->constantArrayFromVmHashTable('getopt_long_'.md5(implode("\0", $longOptions)), $table);
    }
}
