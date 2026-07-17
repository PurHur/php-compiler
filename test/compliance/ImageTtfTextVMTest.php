<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\gd\VmGdFreeType;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for imagettftext()/imagettfbbox() (#6532). */
final class ImageTtfTextVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        if (!VmGdFreeType::available()
            || !\is_readable('/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf')) {
            return;
        }
        yield 'imagettftext.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/imagettftext.phpt',
            'imagettftext.phpt'
        );
    }

    public function setUp(): void
    {
        if (!VmGdFreeType::available()
            || !\is_readable('/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf')) {
            $this->markTestSkipped('libfreetype + DejaVuSans required for #6532');
        }
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
