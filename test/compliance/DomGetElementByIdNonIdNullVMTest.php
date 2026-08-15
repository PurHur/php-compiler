<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: plain id= is not ID-typed — getElementById returns null (#31367). */
final class DomGetElementByIdNonIdNullVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_document_getelementbyid_non_id_null.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_document_getelementbyid_non_id_null.phpt',
            'dom_document_getelementbyid_non_id_null.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
