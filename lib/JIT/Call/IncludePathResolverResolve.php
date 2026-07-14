<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringIncludePathResolver;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** Web IncludePathResolver::resolve static call bridge (#816, bootstrap-aot-link). */
final class IncludePathResolverResolve implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('IncludePathResolver::resolve() requires path and fromFile');
        }
        $path = JitStringArg::lower($context, $args[0], 'IncludePathResolver::resolve() argument #1');
        $fromFile = JitStringArg::lower($context, $args[1], 'IncludePathResolver::resolve() argument #2');
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            StringIncludePathResolver::helperFunction($context),
            [$path, $fromFile]
        );
        $isNull = JitNestedHelperCoerce::isHelperResultNull($context, $raw);
        $nullBb = BasicBlockHelper::append($context, 'include_path_resolver_null');
        $okBb = BasicBlockHelper::append($context, 'include_path_resolver_ok');
        $mergeBb = BasicBlockHelper::append($context, 'include_path_resolver_merge');
        $context->builder->branchIf($isNull, $nullBb, $okBb);

        $slot = JitValueBox::alloc($context);
        $slotPtr = JitValueBox::pointer($context, $slot);

        $context->builder->positionAtEnd($nullBb);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $slotPtr);
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($okBb);
        $resolved = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw);
        $context->builder->call($context->lookupFunction('__value__writeString'), $slotPtr, $resolved);
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($mergeBb);

        return $slot;
    }
}
