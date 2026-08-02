<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\ClosureBindHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * Closure::call() — temporary $this invoke for JIT/AOT (#26872, Zend/zend_closures.c).
 *
 * Avoids {@see ExternalMethod} silent-null on user-script AOT (peer bindTo / fromCallable).
 */
final class ClosureCall implements Call
{
    /** Qualified name for BuiltinParamNames / named-arg resolve. */
    public string $name = 'Closure::call';

    /** @var list<string> php-src Zend/zend_closures.stub.php */
    public array $paramNames = ['newThis', '...args'];

    public int $namedArgsReceiverPrefix = 1;

    public int $namedArgsVariadicIndex = 1;

    public function call(Context $context, Variable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('Closure::call() expects at least 2 arguments (receiver + newThis)');
        }

        return ClosureBindHelper::invokeCall(
            $context,
            $args[0],
            $args[1],
            ...\array_slice($args, 2)
        );
    }
}
