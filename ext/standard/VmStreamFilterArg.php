<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\ResourceState;
use PHPCompiler\VM\ResourceSupport;
use PHPCompiler\VM\Variable;

/** Shared stream-filter resource argument helpers (#16691, ext/standard/streams.c). */
final class VmStreamFilterArg
{
    public static function invalidStreamFilterTypeError(string $functionName): \TypeError
    {
        return new \TypeError(\sprintf(
            '%s(): supplied resource is not a valid stream filter resource',
            $functionName
        ));
    }

    /**
     * Resolve stream_filter_remove() operand — filter id or null when Zend returns false.
     *
     * @return int|null active filter id, or null when caller should return false
     */
    public static function resolveRemoveFilterId(Variable $v, string $functionName): ?int
    {
        $v = $v->resolveIndirect();
        VmStreamArg::rejectEnumCaseOperand($v, $functionName, 1, 'stream_filter');

        if (ResourceSupport::isStreamFilterResource($v)) {
            $filterId = ResourceSupport::resolveHandle($v);
            if (null !== $filterId && VmStreamFilterChain::isValidFilter($filterId)) {
                return $filterId;
            }

            return null;
        }

        $state = ResourceSupport::stateFromVariable($v);
        if (null !== $state && ResourceState::KIND_STREAM_FILTER === $state->kind) {
            return null;
        }

        if ($v->streamFilterResource && Variable::TYPE_INTEGER === $v->type) {
            return null;
        }

        if (ResourceSupport::isVmResource($v) || ResourceSupport::isClosedVmResource($v)) {
            throw self::invalidStreamFilterTypeError($functionName);
        }

        throw new \TypeError(\sprintf(
            '%s(): Argument #1 ($stream_filter) must be of type resource, %s given',
            $functionName,
            VmStreamArg::debugTypeName($v)
        ));
    }
}
