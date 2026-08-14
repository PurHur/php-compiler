<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: DateTimeZone::getLocation lat/lon bit-match Zend (#30953).
 *
 * @group llvm
 * @group jit
 */
final class DateTimeZoneGetLocationCoords30953JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'datetimezone_getlocation_coords_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/datetimezone_getlocation_coords_jit.phpt',
            'datetimezone_getlocation_coords_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
