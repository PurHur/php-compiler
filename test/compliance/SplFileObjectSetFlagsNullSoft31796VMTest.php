<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** Focused VM PHPT for SplFileObject::setFlags(null) soft-null (#31796). */
final class SplFileObjectSetFlagsNullSoft31796VMTest extends BaseTest
{
    protected string $BIN = __DIR__.'/../../bin/vm.php';

    public static function providePHPTests(): \Generator
    {
        $path = __DIR__.'/cases/spl/splfileobject_setflags_null_soft.phpt';
        [$name, $code, $sections] = self::parsePHPT($path, 'splfileobject_setflags_null_soft');
        yield $name => [$name, $code, $sections];
    }
}
