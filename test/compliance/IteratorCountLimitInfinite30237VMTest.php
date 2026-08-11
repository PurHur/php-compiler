<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * Focused VM PHPT for iterator_count LimitIterator(InfiniteIterator) (#30237).
 *
 * Avoids full VMTest data-provider scan (EXTENSIONS sections abort enumeration).
 */
final class IteratorCountLimitInfinite30237VMTest extends BaseTest
{
    protected string $BIN = __DIR__.'/../../bin/vm.php';

    public static function providePHPTests(): \Generator
    {
        $path = __DIR__.'/cases/spl/iterator_count_limit_infinite.phpt';
        [$name, $code, $sections] = self::parsePHPT($path, 'iterator_count_limit_infinite');
        yield $name => [$name, $code, $sections];
    }
}
