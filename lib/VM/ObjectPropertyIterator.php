<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Frame;

/**
 * Foreach / array_walk iterator over user object instance properties.
 *
 * PURPOSE_FOREACH: visibility matches get_object_vars() (#23430).
 * PURPOSE_ARRAY_WALK: full property table with Zend-mangled keys (#23552, #23431, #23565).
 *
 * Values always go through {@see \PHPCompiler\VM::readObjectForeachProperty}.
 * Pass by-ref only when the callback's first parameter is by-ref (#23552).
 */
final class ObjectPropertyIterator
{
    public const PURPOSE_FOREACH = 0;

    public const PURPOSE_ARRAY_WALK = 1;

    /** @var list<string> */
    private array $keys = [];

    /** @var list<string> */
    private array $storageNames = [];

    private int $pos = -1;

    public function __construct(
        private readonly ObjectEntry $object,
        private readonly \PHPCompiler\VM $vm,
        private readonly Frame $frame,
        int $purpose = self::PURPOSE_FOREACH,
    ) {
        if (self::PURPOSE_ARRAY_WALK === $purpose) {
            foreach ($vm->collectObjectArrayWalkPropertyKeys($object, $frame) as $mangledKey) {
                $this->keys[] = $mangledKey;
                $this->storageNames[] = self::storageNameFromMangledKey($mangledKey);
            }

            return;
        }
        $names = array_keys($vm->collectObjectVarsForBuiltin($object, $frame));
        $this->keys = $names;
        $this->storageNames = $names;
    }

    private static function storageNameFromMangledKey(string $key): string
    {
        if (!str_contains($key, "\0")) {
            return $key;
        }
        $parts = explode("\0", $key);

        return $parts[\count($parts) - 1];
    }

    public function reset(): void
    {
        $this->pos = -1;
    }

    public function valid(): bool
    {
        return ++$this->pos < \count($this->keys);
    }

    public function currentKey(): Variable
    {
        $key = new Variable(Variable::TYPE_STRING);
        $key->string($this->keys[$this->pos]);

        return $key;
    }

    public function currentValue(bool $byRef): Variable
    {
        return $this->vm->readObjectForeachProperty(
            $this->object,
            $this->storageNames[$this->pos],
            $this->frame,
            $byRef
        );
    }
}
