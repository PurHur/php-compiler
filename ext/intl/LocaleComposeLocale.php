<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\ReflectionSupport;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/** Locale::composeLocale() — php-src locale_compose (#20738). */
final class LocaleComposeLocale extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('composeLocale');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'Locale::composeLocale() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        $subtags = self::coerceSubtags($frame->calledArgs[0], 'Locale::composeLocale');
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmLocale::composeLocale($subtags, 'Locale::composeLocale');
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    /** @return array<string|int, mixed> */
    public static function coerceSubtags(Variable $var, string $function): array
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #1 ($subtags) must be of type array, %s given',
                $function,
                ReflectionSupport::valueTypeLabelPublic($var)
            ));
        }

        return self::exportSubtags($var->toArray());
    }

    /** @return array<string|int, mixed> */
    public static function exportSubtags(HashTable $ht): array
    {
        $out = [];
        foreach ($ht->iterateKeyed(true) as [$key, $value]) {
            $k = $key->resolveIndirect();
            $v = $value->resolveIndirect();
            $phpKey = Variable::TYPE_STRING === $k->type
                ? $k->toString()
                : (Variable::TYPE_INTEGER === $k->type ? $k->toInt() : (string) $k->toString());
            if (Variable::TYPE_ARRAY === $v->type) {
                $inner = [];
                foreach ($v->toArray()->iterateKeyed(true) as [, $innerVal]) {
                    $iv = $innerVal->resolveIndirect();
                    $inner[] = Variable::TYPE_STRING === $iv->type
                        ? $iv->toString()
                        : $iv;
                }
                $out[$phpKey] = $inner;
            } elseif (Variable::TYPE_STRING === $v->type) {
                $out[$phpKey] = $v->toString();
            } else {
                $out[$phpKey] = $v;
            }
        }

        return $out;
    }
}
