<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Tracks the currently executing script path for __DIR__ / __FILE__ (issues #707, #85, #715).
 */
final class ScriptStack
{
    /** @var list<string> absolute script paths */
    private array $stack = [];

    public function push(string $scriptPath): void
    {
        $normalized = self::normalize($scriptPath);
        if ('' === $normalized) {
            return;
        }
        $this->stack[] = $normalized;
    }

    public function pop(): void
    {
        if ([] !== $this->stack) {
            array_pop($this->stack);
        }
    }

    public function current(): string
    {
        if ([] === $this->stack) {
            return '';
        }

        return $this->stack[count($this->stack) - 1];
    }

    /** Main entry script path (first pushed; ext/standard getmyinode parity). */
    public function root(): string
    {
        if ([] === $this->stack) {
            return '';
        }

        return $this->stack[0];
    }

    public static function normalize(string $path): string
    {
        if ('' === $path || str_contains($path, "\0")) {
            return '';
        }
        $resolved = realpath($path);
        if (false !== $resolved) {
            return $resolved;
        }

        return $path;
    }
}
