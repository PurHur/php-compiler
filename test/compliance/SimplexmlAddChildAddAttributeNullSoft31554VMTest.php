<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: SimpleXMLElement::addChild/addAttribute(null) soft-null DEP then empty ValueError (#31554). */
final class SimplexmlAddChildAddAttributeNullSoft31554VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'addchild_addattribute_null_soft.phpt' => self::parsePHPT(
            __DIR__.'/cases/simplexml/addchild_addattribute_null_soft.phpt',
            'addchild_addattribute_null_soft.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
