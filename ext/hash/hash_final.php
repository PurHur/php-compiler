<?php

declare(strict_types=1);

namespace PHPCompiler\ext\hash;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/**
 * hash_final() — finalize HashContext digest (php-src ext/hash/hash.c; issue #7174).
 *
 * Stub second param is `$binary` (php-src hash.stub.php); legacy InternalArgInfo
 * `raw_output` is overridden via BuiltinParamNames (#23586).
 */
final class hash_final extends HashFunction
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('hash_final() requires one or two arguments in this compiler build');
        }
        $ctx = VmHashContext::requireHashContext($frame->calledArgs[0], 'hash_final', 1);
        $raw = false;
        if (2 === $argc) {
            $rawArg = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $rawArg->type) {
                throw new \LogicException('hash_final() binary must be boolean in this compiler build');
            }
            $raw = $rawArg->toBool();
        }
        $digest = VmHashContext::final($ctx, $raw);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($digest): void {
            $ret->string($digest);
        });
    }
}
