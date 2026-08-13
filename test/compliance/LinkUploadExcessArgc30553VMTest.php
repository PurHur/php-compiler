<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: link/upload excess argc → ArgumentCountError (#30553). */
final class LinkUploadExcessArgc30553VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_link_upload_30553.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_link_upload_30553.phpt',
            'excess_argc_link_upload_30553.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
