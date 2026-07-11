<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for iptcembed() (ext/standard/iptc.c; issue #6104). */
final class JitIptcEmbed
{
    /**
     * @param JITVariable[] $args
     */
    public static function invoke(Context $context, array $args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('iptcembed() expects between 2 and 3 arguments in this compiler build');
        }

        $iptcLit = JitStringArg::compileTimeLiteral($args[0]);
        $pathLit = JitStringArg::compileTimeLiteral($args[1]);
        if (null === $iptcLit || null === $pathLit) {
            throw new \LogicException(
                'iptcembed() requires compile-time string literals for JIT/AOT in this compiler build'
            );
        }
        if (str_contains($pathLit, "\0")) {
            throw new \ValueError(
                'iptcembed(): Argument #2 ($filename) must not contain any null bytes'
            );
        }

        if (isset($args[2])) {
            throw new \LogicException(
                'iptcembed() optional spool is not lowered for JIT/AOT in this compiler build'
            );
        }

        $result = VmIptc::embed($iptcLit, $pathLit, 0);
        if (false === $result) {
            return $context->constantFromBool(false);
        }
        if (\is_string($result)) {
            return JitFileGetContents::wrapString($context, $context->constantFromString($result));
        }

        return $context->constantFromBool(true);
    }
}
