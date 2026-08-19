<?php

declare(strict_types=1);

namespace PHPCompiler\ext\hash;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmStreamArg;
use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/**
 * hash_update_stream() — incremental hash from stream resources (php-src ext/hash/hash.c; #6681, JIT/AOT #32483).
 */
final class hash_update_stream extends HashFunction
{
    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, 'hash_update_stream', 2, 3);
        $argc = \count($frame->calledArgs);
        $ctx = VmHashContext::requireHashContext($frame->calledArgs[0], 'hash_update_stream', 1);
        $handle = VmStreamArg::requireStreamHandle(
            $frame->calledArgs[1]->resolveIndirect(),
            'hash_update_stream',
            2
        );
        $length = -1;
        if (3 === $argc) {
            $length = VmMath::parseIntBuiltinArg(
                $frame->calledArgs[2]->resolveIndirect(),
                'hash_update_stream',
                3,
                'length'
            );
        }
        $read = VmHashContext::updateFromStream($ctx, $handle, $length);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($read): void {
            if (false === $read) {
                $ret->bool(false);

                return;
            }
            $ret->int($read);
        });
    }
}
