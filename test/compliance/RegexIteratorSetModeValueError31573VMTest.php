<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** Focused VM PHPT for RegexIterator::setMode ValueError citation (#31573). */
final class RegexIteratorSetModeValueError31573VMTest extends BaseTest
{
    protected string $BIN = __DIR__.'/../../bin/vm.php';

    public static function providePHPTests(): \Generator
    {
        $path = __DIR__.'/cases/spl/regexiterator_setmode_valueerror_31573.phpt';
        [$name, $code, $sections] = self::parsePHPT($path, 'regexiterator_setmode_valueerror_31573');
        yield $name => [$name, $code, $sections];
    }
}
