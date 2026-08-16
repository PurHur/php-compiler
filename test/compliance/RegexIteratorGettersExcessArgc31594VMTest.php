<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** Focused VM PHPT for RegexIterator zero-arg getters excess argc (#31594). */
final class RegexIteratorGettersExcessArgc31594VMTest extends BaseTest
{
    protected string $BIN = __DIR__.'/../../bin/vm.php';

    public static function providePHPTests(): \Generator
    {
        $path = __DIR__.'/cases/spl/regexiterator_getters_excess_argc_31594.phpt';
        [$name, $code, $sections] = self::parsePHPT($path, 'regexiterator_getters_excess_argc_31594');
        yield $name => [$name, $code, $sections];
    }
}
