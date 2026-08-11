<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * Focused VM PHPT for array_* unary internal string callbacks (#30228).
 */
final class ArrayAllUnaryInternalCallback30228VMTest extends BaseTest
{
    protected string $BIN = __DIR__.'/../../bin/vm.php';

    public static function providePHPTests(): \Generator
    {
        $path = __DIR__.'/cases/stdlib/array_all_unary_internal_callback.phpt';
        [$name, $code, $sections] = self::parsePHPT($path, 'array_all_unary_internal_callback');
        yield $name => [$name, $code, $sections];
    }
}
