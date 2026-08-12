<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: libxml null entity loader warning text on PROFILE=8.4 (#30424). */
final class DomLibxmlNullEntityLoaderMessageForward84VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_libxml_null_entity_loader_message_forward84.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_libxml_null_entity_loader_message_forward84.phpt',
            'dom_libxml_null_entity_loader_message_forward84.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
