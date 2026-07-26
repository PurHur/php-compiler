<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xsl;

use PHPCompiler\Func;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmCallable;

/**
 * Host-named trampolines for XSLT php:function() → VM userland (#22632).
 *
 * Host ext/xsl looks up handlers in the Zend function table. VM-defined functions
 * are invisible there, so registerPHPFunctions() alone yields
 * "Unable to call handler". This bridge installs thin host functions that
 * re-enter {@see VmCallable} with the live VM {@see Context}.
 *
 * php-src: ext/xsl/xsltprocessor.c + ext/dom/xpath_callbacks.c
 */
final class XsltPhpFunctionBridge
{
    /**
     * @var array<string, array{ctx: Context, name: string}>
     */
    private static array $handlers = [];

    /**
     * @var array<string, true>
     */
    private static array $installed = [];

    /**
     * Install/refresh host trampolines for the names php:function() may call.
     *
     * @param null|string|list<string> $restrict null = all VM user funcs (Zend mode 1)
     */
    public static function sync(Context $ctx, null|string|array $restrict): void
    {
        foreach (self::namesToBridge($ctx, $restrict) as $name) {
            self::ensureHostTrampoline($name);
            self::$handlers[strtolower($name)] = [
                'ctx' => $ctx,
                'name' => $name,
            ];
        }
    }

    /**
     * Called from eval'd host trampolines during transformTo*.
     *
     * @param list<mixed> $args
     */
    public static function dispatch(string $name, array $args): mixed
    {
        $lc = strtolower($name);
        if (!isset(self::$handlers[$lc])) {
            throw new \Error(sprintf('Call to undefined function %s()', $name));
        }
        $entry = self::$handlers[$lc];
        $callback = new Variable(Variable::TYPE_STRING);
        $callback->string($entry['name']);
        $vmArgs = [];
        foreach ($args as $arg) {
            $vmArgs[] = self::phpToVariable($arg);
        }

        return self::variableToPhp(VmCallable::invoke($entry['ctx'], $callback, ...$vmArgs));
    }

    /**
     * @param null|string|list<string> $restrict
     *
     * @return list<string>
     */
    private static function namesToBridge(Context $ctx, null|string|array $restrict): array
    {
        if (null === $restrict) {
            $names = [];
            foreach ($ctx->functions as $fn) {
                if (!$fn instanceof Func\PHP) {
                    continue;
                }
                $name = $fn->getName();
                $lc = strtolower($name);
                // Host builtins stay native; our own prior trampolines still need handler refresh.
                if (\function_exists($name) && !isset(self::$installed[$lc])) {
                    continue;
                }
                $names[] = $name;
            }

            return $names;
        }
        if (\is_string($restrict)) {
            return self::needsTrampoline($restrict) ? [$restrict] : [];
        }
        $names = [];
        foreach ($restrict as $name) {
            if (self::needsTrampoline($name)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    private static function needsTrampoline(string $name): bool
    {
        return !\function_exists($name) || isset(self::$installed[strtolower($name)]);
    }

    private static function ensureHostTrampoline(string $name): void
    {
        $lc = strtolower($name);
        if (isset(self::$installed[$lc])) {
            return;
        }
        if (\function_exists($name)) {
            return;
        }
        if (!preg_match('/^[a-zA-Z_\\\\][a-zA-Z0-9_\\\\]*$/', $name)) {
            throw new \LogicException(sprintf(
                'XSLT php:function bridge refused invalid handler name %s',
                $name
            ));
        }
        if (str_contains($name, '\\')) {
            $parts = explode('\\', $name);
            $short = array_pop($parts);
            $ns = implode('\\', $parts);
            $code = sprintf(
                'namespace %s { if (!\\function_exists(%s)) { function %s(...$args) { return \\%s::dispatch(%s, $args); } } }',
                $ns,
                var_export($name, true),
                $short,
                self::class,
                var_export($name, true)
            );
        } else {
            $code = sprintf(
                'namespace { if (!\\function_exists(%s)) { function %s(...$args) { return \\%s::dispatch(%s, $args); } } }',
                var_export($name, true),
                $name,
                self::class,
                var_export($name, true)
            );
        }
        eval($code);
        self::$installed[$lc] = true;
    }

    private static function phpToVariable(mixed $value): Variable
    {
        $slot = new Variable();
        if (null === $value) {
            $slot->null();

            return $slot;
        }
        if (\is_bool($value)) {
            $slot->bool($value);

            return $slot;
        }
        if (\is_int($value)) {
            $slot->int($value);

            return $slot;
        }
        if (\is_float($value)) {
            $slot->float($value);

            return $slot;
        }
        if (\is_string($value)) {
            $slot->string($value);

            return $slot;
        }
        if (\is_array($value)) {
            $ht = new HashTable();
            foreach ($value as $key => $item) {
                $ht->update((string) $key, self::phpToVariable($item));
            }
            $slot->array($ht);

            return $slot;
        }
        // Host DOMNode from php:function node-set args — stringify like Zend
        // convert paths when the callee expects a string; keep object label otherwise.
        if ($value instanceof \DOMNode) {
            $slot->string($value->textContent ?? '');

            return $slot;
        }
        $slot->string((string) $value);

        return $slot;
    }

    private static function variableToPhp(Variable $var): mixed
    {
        $var = $var->resolveIndirect();

        return match ($var->type) {
            Variable::TYPE_NULL => null,
            Variable::TYPE_BOOLEAN => $var->toBool(),
            Variable::TYPE_INTEGER => $var->toInt(),
            Variable::TYPE_FLOAT => $var->toFloat(),
            Variable::TYPE_STRING => $var->toString(),
            // Zend convert_to_string on non-scalars for XPath string results.
            default => $var->toString(),
        };
    }
}
