<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * get_resource_type() — stream handle introspection (#3142).
 *
 * php-src: Zend/zend_builtin_functions.c — PHP_FUNCTION(get_resource_type)
 *
 * Excess argc → Zend ArgumentCountError (#30707).
 */
final class get_resource_type extends Internal
{
    private const TYPE_ERROR = 'get_resource_type(): Argument #1 ($resource) must be of type resource, %s given';

    public function execute(Frame $frame): void
    {
        // php-src stub arity: exactly 1 (#30707; Zend/zend_builtin_functions.c).
        $this->requireExactArgCount($frame, 'get_resource_type', 1);
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        VmStreamArg::rejectEnumCaseOperand($v, 'get_resource_type');
        if ($v->isStreamResource()) {
            $handle = \PHPCompiler\VM\ResourceSupport::resolveHandle($v);
            if (null !== $handle) {
                $frame->returnVar->string(VmFs::resourceTypeForStreamTag($handle));

                return;
            }
        }
        if ($v->isBrigadeResource()) {
            $handle = \PHPCompiler\VM\ResourceSupport::resolveHandle($v);
            $type = null !== $handle ? VmStreamBucket::getResourceType($handle, true) : null;
            if (null !== $type) {
                $frame->returnVar->string($type);

                return;
            }
        }
        if ($v->isBucketResource()) {
            $handle = \PHPCompiler\VM\ResourceSupport::resolveHandle($v);
            $type = null !== $handle ? VmStreamBucket::getResourceType($handle, false) : null;
            if (null !== $type) {
                $frame->returnVar->string($type);

                return;
            }
        }
        if ($v->isStreamFilterResource()) {
            $handle = \PHPCompiler\VM\ResourceSupport::resolveHandle($v);
            $type = null !== $handle ? VmStreamFilterChain::getResourceType($handle) : null;
            if (null !== $type) {
                $frame->returnVar->string($type);

                return;
            }
        }
        if ($v->isProcessResource()) {
            $handle = \PHPCompiler\VM\ResourceSupport::resolveHandle($v);
            if (null !== $handle && VmProcess::isValidHandle($handle)) {
                $frame->returnVar->string('process');

                return;
            }
        }
        if (\PHPCompiler\VM\ResourceSupport::isStreamContextResource($v)) {
            $frame->returnVar->string('stream-context');

            return;
        }
        if ($v->isDirResource()) {
            $handle = \PHPCompiler\VM\ResourceSupport::resolveHandle($v);
            if (null !== $handle && VmDir::isValidHandle($handle)) {
                $frame->returnVar->string('stream');

                return;
            }
        }
        if (!is_resource_::isResource($v)) {
            throw new \TypeError(\sprintf(self::TYPE_ERROR, VmStreamArg::debugTypeName($v)));
        }
        $handle = \PHPCompiler\VM\ResourceSupport::resolveHandle($v);
        $type = null !== $handle ? VmFs::getResourceType($handle) : null;
        if (null === $type) {
            throw new \TypeError(\sprintf(self::TYPE_ERROR, VmStreamArg::debugTypeName($v)));
        }
        $frame->returnVar->string($type);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30707 / peer #30687).
        if (!$this->requireExactJitArgCount($context, $args, 'get_resource_type', 1)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitGetResourceType::invoke($context, $args[0]);
    }
}
