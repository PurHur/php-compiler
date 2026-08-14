<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: DateTimeZone::getLocation lat/lon bit-match Zend (#30953). */
final class DateTimeZoneGetLocationCoords30953VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'datetimezone_getlocation_coords.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/datetimezone_getlocation_coords.phpt',
            'datetimezone_getlocation_coords.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
