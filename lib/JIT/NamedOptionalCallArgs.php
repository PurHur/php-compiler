<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * Densify sparse internal-call arg maps before variadic spread (issue #9525).
 *
 * NamedArgs::resolveOutgoing() preserves parameter indices; PHP's argument spread
 * drops holes so optional middle parameters must be padded before Internal::call().
 */
final class NamedOptionalCallArgs
{
    /**
     * @param array<int, Variable> $callArgs
     *
     * @return list<Variable>
     */
    public static function densifyForSpread(Context $context, array $callArgs, int $paramCount): array
    {
        if ([] === $callArgs || array_is_list($callArgs)) {
            return $callArgs;
        }
        if ($paramCount <= 0) {
            return array_values($callArgs);
        }
        $out = [];
        for ($i = 0; $i < $paramCount; ++$i) {
            if (isset($callArgs[$i])) {
                $out[] = $callArgs[$i];
                continue;
            }
            $null = new Variable(
                $context,
                Variable::TYPE_NULL,
                Variable::KIND_VALUE,
                $context->getTypeFromString('__value__*')->constNull()
            );
            $null->isNullConstant = true;
            $null->isOptionalOmittedNamedArg = true;
            $out[] = $null;
        }

        return $out;
    }

    public static function isOmittedOptional(Variable $arg): bool
    {
        return Variable::TYPE_NULL === $arg->type
            && ($arg->isNullConstant ?? false)
            && ($arg->isOptionalOmittedNamedArg ?? false);
    }
}
