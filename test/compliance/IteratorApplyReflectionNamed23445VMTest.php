<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** Focused VM PHPT for iterator_apply Reflection/named args (#23445). */
final class IteratorApplyReflectionNamed23445VMTest extends BaseTest
{
    protected string $BIN = __DIR__.'/../../bin/vm.php';

    public static function providePHPTests(): \Generator
    {
        $path = __DIR__.'/cases/stdlib/iterator_apply_reflection_named.phpt';
        [$name, $code, $sections] = self::parsePHPT($path, 'iterator_apply_reflection_named');
        yield $name => [$name, $code, $sections];
    }
}
