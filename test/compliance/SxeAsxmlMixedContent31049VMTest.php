<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: SimpleXMLElement::asXML() mixed content + attributed text (#31049). */
final class SxeAsxmlMixedContent31049VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'asxml_mixed_content.phpt' => self::parsePHPT(
            __DIR__.'/cases/simplexml/asxml_mixed_content.phpt',
            'asxml_mixed_content.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
