<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Foreach iterator over WeakMap entries — keys are live object handles, not stored strings (#4434).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/weakrefs.c
 */
final class WeakMapIterator
{
    /** @var list<array{0: Variable, 1: Variable}> */
    private array $pairs = [];

    private int $pos = -1;

    public function __construct(ObjectEntry $weakMap)
    {
        WeakRefSupport::purgeStaleMapEntries($weakMap);
        $ht = WeakRefSupport::mapTable($weakMap);
        if (null === $ht) {
            return;
        }
        foreach ($ht->iterateKeyed(true) as $pair) {
            [$storedKeyVar, $value] = $pair;
            $storedKeyVar = $storedKeyVar->resolveIndirect();
            // bucketKeyToVariable materializes live o:<id> keys to TYPE_OBJECT (#19369 / #4434).
            if (Variable::TYPE_OBJECT === $storedKeyVar->type) {
                if (!WeakRefSupport::isTargetAlive($storedKeyVar)) {
                    continue;
                }
                $this->pairs[] = [$storedKeyVar, $value];
                continue;
            }
            if (EnumCaseSupport::isEnumCaseVariable($storedKeyVar)) {
                $this->pairs[] = [$storedKeyVar, $value];
                continue;
            }
            if (Variable::TYPE_STRING !== $storedKeyVar->type) {
                continue;
            }
            $keyObject = WeakRefSupport::resolveMapKeyVariable($storedKeyVar->toString());
            if (null === $keyObject) {
                continue;
            }
            $this->pairs[] = [$keyObject, $value];
        }
    }

    public function reset(): void
    {
        $this->pos = -1;
    }

    public function valid(): bool
    {
        return ++$this->pos < \count($this->pairs);
    }

    public function currentKey(): Variable
    {
        return $this->pairs[$this->pos][0];
    }

    public function currentValue(bool $byRef): Variable
    {
        $value = $this->pairs[$this->pos][1];
        if ($byRef) {
            return $value;
        }
        $copy = new Variable();
        $copy->copyFrom($value->resolveIndirect());

        return $copy;
    }
}
