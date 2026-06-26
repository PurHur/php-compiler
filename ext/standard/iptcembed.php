<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** iptcembed() — embed IPTC metadata in JPEG (ext/standard/iptc.c; issue #6104). */
final class iptcembed extends Internal
{
    public function __construct()
    {
        parent::__construct('iptcembed');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(
                'iptcembed() expects between 2 and 3 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $iptcData = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'iptcembed',
            0,
            'iptcdata'
        );
        $jpegPath = VmString::coercePathBuiltinArg(
            $frame->calledArgs[1],
            'iptcembed',
            1,
            'filename'
        );
        $spool = 0;
        if (3 === $argc) {
            $spoolArg = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $spoolArg->type) {
                throw new \TypeError(
                    'iptcembed(): Argument #3 ($spool) must be of type int, '
                    .VmString::typeNameForVariable($spoolArg).' given'
                );
            }
            $spool = $spoolArg->toInt();
        }

        $result = VmIptc::embed($iptcData, $jpegPath, $spool);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        if (\is_string($result)) {
            $frame->returnVar->string($result);

            return;
        }
        $frame->returnVar->bool(true);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitIptcEmbed::invoke($context, $args);
    }
}
