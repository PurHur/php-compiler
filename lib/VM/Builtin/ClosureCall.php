<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ClosureSupport;
use PHPCompiler\VM\Variable;

/** Closure::call() — temporary $this invoke (issue #4927, Zend zend_closures.c zif_closure_call). */
final class ClosureCall extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('call');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \ArgumentCountError(
                'Closure::call() expects at least 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('Closure::call() requires VM context');
        }
        [$state, $newThis, $invokeArgs] = self::resolveCallOperands($frame);
        $result = ClosureSupport::call(
            $frame->vmContext,
            $state,
            $newThis,
            $invokeArgs,
            'Closure::call()',
            $frame
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->copyFrom($result);
    }

    /**
     * Instance $closure->call($obj, ...) — calledArgs are [closure, newThis, ...] (#4927, #6411).
     * Static Closure::call() is rejected by assertMethodCallableStatically (#7144).
     *
     * @return array{0: ClosureState, 1: Variable, 2: list<Variable>}
     */
    private static function resolveCallOperands(Frame $frame): array
    {
        $args = $frame->calledArgs;
        $closureArg = $args[0]->resolveIndirect();
        if (
            Variable::TYPE_OBJECT === $closureArg->type
            && null !== $closureArg->toObject()->closureState
        ) {
            if (\count($args) < 2) {
                throw new \ArgumentCountError(
                    'Closure::call() expects at least 2 arguments, '.\count($args).' given'
                );
            }

            return [
                ClosureSupport::requireClosureState($closureArg->toObject(), 'Closure::call()'),
                $args[1],
                \array_slice($args, 2),
            ];
        }
        if (!empty($frame->callArgs)) {
            $receiver = $frame->callArgs[0]->resolveIndirect();
            if (
                Variable::TYPE_OBJECT === $receiver->type
                && null !== $receiver->toObject()->closureState
            ) {
                if (\count($args) < 1) {
                    throw new \ArgumentCountError(
                        'Closure::call() expects at least 1 argument, '.\count($args).' given'
                    );
                }

                return [
                    ClosureSupport::requireClosureState($receiver->toObject(), 'Closure::call()'),
                    $args[0],
                    \array_slice($args, 1),
                ];
            }
        }
        throw new \LogicException('Closure::call() called without closure receiver');
    }
}
