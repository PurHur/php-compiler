<?php

declare(strict_types=1);

/**
 * Bootstrap AOT: FuncCall $call->args[n] (ConstStringFolder::foldCallArgString pattern).
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

function hasCallArg(DeployCallStub $call, int $index): int
{
    return isset($call->args[$index]) ? 1 : 0;
}

$call = new DeployCallStub(['templates', 'fallback']);
echo (string) hasCallArg($call, 0)."\n";
