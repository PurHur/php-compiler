<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** Focused VM PHPT for iterator_count Reflection/named arg (#23423). */
final class IteratorCountReflectionNamed23423VMTest extends BaseTest
{
    protected string $BIN = __DIR__.'/../../bin/vm.php';

    public static function providePHPTests(): \Generator
    {
        $path = __DIR__.'/cases/stdlib/iterator_count_reflection_named.phpt';
        [$name, $code, $sections] = self::parsePHPT($path, 'iterator_count_reflection_named');
        yield $name => [$name, $code, $sections];
    }
}
