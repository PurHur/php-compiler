<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM compliance: Uri\Rfc3986 phantoms retired for php-src-strict (#28198).
 * Also covers UriBuilder + resolve/equals surface from #20950.
 */
final class UriRfc3986PhantomsVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        $file = 'rfc3986_uribuilder_api.phpt';
        yield 'uri/'.$file => self::parsePHPT(
            __DIR__.'/cases/uri/'.$file,
            $file
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
