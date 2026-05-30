<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * get_resource_type() — stream handle introspection (#3142).
 *
 * php-src: ext/standard/file.c — PHP_FUNCTION(get_resource_type)
 */
final class get_resource_type extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('get_resource_type() requires exactly one argument');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (!is_resource_::isResource($v)) {
            throw new \LogicException('get_resource_type() expects a stream resource');
        }
        $type = VmFs::getResourceType($v->toInt());
        if (null === $type) {
            throw new \LogicException('get_resource_type() expects a valid stream resource');
        }
        $frame->returnVar->string($type);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('get_resource_type() requires exactly one argument');
        }
        $i64 = $context->getTypeFromString('int64');

        return JitGetResourceType::invoke(
            $context,
            $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[0], 'get_resource_type() resource'),
                $i64
            )
        );
    }
}
