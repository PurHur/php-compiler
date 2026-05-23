<?php

declare(strict_types=1);

/**
 * Bootstrap AOT: isset/?? on FuncCall-style args (ConstStringFolder::funcCallHasArity pattern).
 */

final class DeployCallStub
{
    /** @var list<string> */
    public array $args;

    public function __construct(array $args)
    {
        $this->args = $args;
    }
}

function missingArg(DeployCallStub $call, int $index): int
{
    return isset($call->args[$index]) ? 0 : 1;
}

$call = new DeployCallStub(['templates']);
echo (string) missingArg($call, 1)."\n";
