<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlreader;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;

/**
 * Shared VM wiring for XMLReader methods — user-argc guards (#30641).
 *
 * Mirrors DomClassMethod (#30616): Zend ArgumentCountError messages exclude $this.
 */
abstract class XmlReaderClassMethod extends VmClassMethod
{
    /** User args only — drop $this when present (instance call). */
    protected function userArgCount(Frame $frame, bool $hasThis = true): int
    {
        $n = \count($frame->calledArgs);

        return max(0, $hasThis ? $n - 1 : $n);
    }

    protected function requireExactUserArgCount(
        Frame $frame,
        string $function,
        int $expected,
        bool $hasThis = true
    ): void {
        $given = $this->userArgCount($frame, $hasThis);
        if ($given !== $expected) {
            throw new \ArgumentCountError(self::exactUserArgCountMessage($function, $expected, $given));
        }
    }

    protected function requireAtMostUserArgCount(
        Frame $frame,
        string $function,
        int $maximum,
        bool $hasThis = true
    ): void {
        $given = $this->userArgCount($frame, $hasThis);
        if ($given > $maximum) {
            throw new \ArgumentCountError(self::atMostUserArgCountMessage($function, $maximum, $given));
        }
    }

    protected function requireUserArgCountRange(
        Frame $frame,
        string $function,
        int $minimum,
        int $maximum,
        bool $hasThis = true
    ): void {
        $given = $this->userArgCount($frame, $hasThis);
        if ($given < $minimum) {
            throw new \ArgumentCountError(self::atLeastUserArgCountMessage($function, $minimum, $given));
        }
        if ($given > $maximum) {
            throw new \ArgumentCountError(self::atMostUserArgCountMessage($function, $maximum, $given));
        }
    }

    public static function exactUserArgCountMessage(string $function, int $expected, int $given): string
    {
        return \sprintf(
            '%s() expects exactly %d argument%s, %d given',
            $function,
            $expected,
            1 === $expected ? '' : 's',
            $given
        );
    }

    public static function atMostUserArgCountMessage(string $function, int $maximum, int $given): string
    {
        return \sprintf(
            '%s() expects at most %d argument%s, %d given',
            $function,
            $maximum,
            1 === $maximum ? '' : 's',
            $given
        );
    }

    public static function atLeastUserArgCountMessage(string $function, int $minimum, int $given): string
    {
        return \sprintf(
            '%s() expects at least %d argument%s, %d given',
            $function,
            $minimum,
            1 === $minimum ? '' : 's',
            $given
        );
    }
}
