<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * get_resource_type() — stream handle introspection (#3142).
 *
 * php-src: ext/standard/file.c — PHP_FUNCTION(get_resource_type)
 */
final class get_resource_type extends Internal
{
    private const TYPE_ERROR = 'get_resource_type(): Argument #1 ($resource) must be of type resource, %s given';

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('get_resource_type() requires exactly one argument');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        VmStreamArg::rejectEnumCaseOperand($v, 'get_resource_type');
        if ($v->isStreamResource()) {
            $frame->returnVar->string(VmFs::resourceTypeForStreamTag($v->toInt()));

            return;
        }
        if ($v->isBrigadeResource()) {
            $type = VmStreamBucket::getResourceType($v->toInt(), true);
            if (null !== $type) {
                $frame->returnVar->string($type);

                return;
            }
        }
        if ($v->isBucketResource()) {
            $type = VmStreamBucket::getResourceType($v->toInt(), false);
            if (null !== $type) {
                $frame->returnVar->string($type);

                return;
            }
        }
        if (!is_resource_::isResource($v)) {
            throw new \TypeError(\sprintf(self::TYPE_ERROR, VmStreamArg::debugTypeName($v)));
        }
        $type = VmFs::getResourceType($v->toInt());
        if (null === $type) {
            throw new \TypeError(\sprintf(self::TYPE_ERROR, VmStreamArg::debugTypeName($v)));
        }
        $frame->returnVar->string($type);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('get_resource_type() requires exactly one argument');
        }

        return JitGetResourceType::invoke($context, $args[0]);
    }
}
