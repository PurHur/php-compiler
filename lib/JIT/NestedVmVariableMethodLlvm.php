<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * Nested JIT lowering for {@see \PHPCompiler\VM\Variable} instance helpers (#12910).
 */
final class NestedVmVariableMethodLlvm
{
    /** @var array<string, class-string<Call>> */
    private const METHOD_HANDLERS = [
        'resolveindirect' => Call\VariableResolveIndirect::class,
        'tostring' => Call\VariableToString::class,
        'toint' => Call\VariableToInt::class,
        'tofloat' => Call\VariableToFloat::class,
        'tobool' => Call\VariableToBool::class,
        'toarray' => Call\VariableToArray::class,
        'null' => Call\VariableWriteNested::class,
        'int' => Call\VariableWriteNested::class,
        'bool' => Call\VariableWriteNested::class,
        'string' => Call\VariableWriteNested::class,
        'float' => Call\VariableWriteNested::class,
        'array' => Call\VariableWriteNested::class,
    ];

    public static function ensureMethod(Context $context, string $methodLc): bool
    {
        $handler = self::METHOD_HANDLERS[$methodLc] ?? null;
        if (null === $handler) {
            return false;
        }
        $proxyName = 'phpcompiler\\vm\\variable::'.$methodLc;
        if ($context->functionIsRegistered($proxyName)) {
            return true;
        }
        if (Call\VariableWriteNested::class === $handler) {
            $context->functionProxies[$proxyName] = new Call\VariableWriteNested($methodLc);
        } else {
            $context->functionProxies[$proxyName] = new $handler();
        }

        return true;
    }

    public static function isNestedVariableMethod(string $methodLc): bool
    {
        return isset(self::METHOD_HANDLERS[$methodLc]);
    }
}
