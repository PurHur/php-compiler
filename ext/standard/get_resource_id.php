<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * get_resource_id() — stable stream handle id (ext/standard/basic_functions.c parity, #3180).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/basic_functions.c PHP_FUNCTION(get_resource_id)
 */
final class get_resource_id extends Internal
{
    private const TYPE_ERROR = 'get_resource_id(): Argument #1 ($resource) must be of type resource, %s given';

    public function __construct()
    {
        parent::__construct('get_resource_id');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('get_resource_id() requires exactly one argument');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (!is_resource_::isResource($v)) {
            throw new \TypeError(\sprintf(self::TYPE_ERROR, VmStreamArg::debugTypeName($v)));
        }
        $frame->returnVar->int($v->toInt());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('get_resource_id() requires exactly one argument');
        }

        return JitGetResourceId::invoke($context, $args[0]);
    }
}
