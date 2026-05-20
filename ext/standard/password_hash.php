<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** password_hash() — PASSWORD_DEFAULT / PASSWORD_BCRYPT only; VM only (issue #172). */
final class password_hash extends Internal
{
    public function __construct()
    {
        parent::__construct('password_hash');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('password_hash() requires two or three arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $password = $frame->calledArgs[0]->resolveIndirect();
        $algo = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_STRING !== $password->type) {
            throw new \LogicException('password_hash() requires a string password in this compiler build');
        }
        if (Variable::TYPE_INTEGER !== $algo->type) {
            throw new \LogicException('password_hash() requires an integer algorithm in this compiler build');
        }
        $options = [];
        if (3 === $argc) {
            $optVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_ARRAY !== $optVar->type) {
                throw new \LogicException('password_hash() options must be an array in this compiler build');
            }
            $exported = VmJson::export($optVar);
            if (!\is_array($exported)) {
                throw new \LogicException('password_hash() options must be an array in this compiler build');
            }
            $options = $exported;
        }
        $result = VmPassword::hash($password->toString(), $algo->toInt(), $options);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('password_hash() is not implemented for JIT in this compiler build');
    }
}
