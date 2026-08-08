<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * VM: anonymous class implementing interface is Interface@anonymous (#28840).
 *
 * Dedicated provider — full VMTest discovery currently dies on unrelated
 * --EXTENSIONS-- phpts, and path-slash data-set names break --filter.
 */
require_once __DIR__.'/../BaseTest.php';

final class AnonymousClassImplementsNameVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'anonymous_class_implements_name.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/anonymous_class_implements_name.phpt',
            'anonymous_class_implements_name.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
