<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ClosureSupport;
use PHPCompiler\VM\Variable;

/** Closure::bindTo() — VM (#3266). */
final class ClosureBindTo extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('bindTo');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('Closure::bindTo() expects at least 2 arguments');
        }
        // php-src: Zend/zend_closures.c — ZEND_PARSE_PARAMETERS(1, 2); $calledArgs[0] is $this (#30867)
        $this->requireUserArgCountRange($frame, 'Closure::bindTo', 1, 2);
        if (null === $frame->vmContext) {
            throw new \LogicException('Closure::bindTo() requires VM context');
        }
        [$state, $newThis, $newScope] = self::resolveBindToOperands($frame);
        $bound = ClosureSupport::bindTo(
            $frame->vmContext,
            $state,
            $newThis,
            $newScope,
            'Closure::bindTo()',
            $frame
        );
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $bound) {
            $frame->returnVar->null();

            return;
        }
        $ret = new Variable(Variable::TYPE_OBJECT);
        $ret->object($bound);
        $frame->returnVar->copyFrom($ret);
    }

    /**
     * Instance $closure->bindTo($newThis, $newScope) — receiver may live in callArgs (#15899, #4927).
     *
     * @return array{0: \PHPCompiler\VM\ClosureState, 1: Variable, 2: ?Variable}
     */
    private static function resolveBindToOperands(Frame $frame): array
    {
        $args = $frame->calledArgs;
        $closureArg = $args[0]->resolveIndirect();
        if (
            Variable::TYPE_OBJECT === $closureArg->type
            && null !== $closureArg->toObject()->closureState
        ) {
            if (\count($args) < 2) {
                throw new \LogicException('Closure::bindTo() expects at least 2 arguments');
            }

            return [
                ClosureSupport::requireClosureState($closureArg->toObject(), 'Closure::bindTo()'),
                $args[1],
                $args[2] ?? null,
            ];
        }
        if (!empty($frame->callArgs)) {
            $receiver = $frame->callArgs[0]->resolveIndirect();
            if (
                Variable::TYPE_OBJECT === $receiver->type
                && null !== $receiver->toObject()->closureState
            ) {
                if (\count($args) < 1) {
                    throw new \LogicException('Closure::bindTo() expects at least 2 arguments');
                }

                return [
                    ClosureSupport::requireClosureState($receiver->toObject(), 'Closure::bindTo()'),
                    $args[0],
                    $args[1] ?? null,
                ];
            }
        }
        throw new \LogicException('Closure::bindTo() called without $this');
    }
}
