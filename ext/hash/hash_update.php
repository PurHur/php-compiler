<?php

declare(strict_types=1);

namespace PHPCompiler\ext\hash;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/**
 * hash_update() — append data to HashContext (php-src ext/hash/hash.c; issue #7174, #20195).
 */
final class hash_update extends HashFunction
{
    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('hash_update() requires exactly two arguments in this compiler build');
        }
        $ctx = VmHashContext::requireHashContext($frame->calledArgs[0], 'hash_update', 1);
        // Z_PARAM_STR $data — null TypeError on 8.4 forward profile (#20195, ext/hash/hash.c).
        $data = VmString::zparamStrBuiltinArgForFrame($frame, 1, 'hash_update', 1, 'data');
        VmHashContext::update($ctx, $data);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret): void {
            $ret->bool(true);
        });
    }
}
