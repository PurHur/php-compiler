<?php

declare(strict_types=1);

/**
 * exit()/die() Stringable object status — Zend echoes __toString() (#18469).
 */

$object = new class {
    public function __toString(): string
    {
        return 'bye';
    }
};

$mode = $argv[1] ?? 'exit';
if ('die' === $mode) {
    die($object);
}
exit($object);
