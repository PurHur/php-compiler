<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** Focused VM PHPT for RegexIterator setters soft-null (#31748). */
final class RegexIteratorSettersNullSoft31748VMTest extends BaseTest
{
    protected string $BIN = __DIR__.'/../../bin/vm.php';

    public static function providePHPTests(): \Generator
    {
        $path = __DIR__.'/cases/spl/regexiterator_setters_null_soft.phpt';
        [$name, $code, $sections] = self::parsePHPT($path, 'regexiterator_setters_null_soft');
        yield $name => [$name, $code, $sections];
    }
}
