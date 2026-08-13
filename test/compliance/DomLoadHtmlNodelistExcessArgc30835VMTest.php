<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: loadHTML/NodeList/NamedNodeMap excess argc → ArgumentCountError (#30835). */
final class DomLoadHtmlNodelistExcessArgc30835VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_dom_loadhtml_nodelist_30835.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_dom_loadhtml_nodelist_30835.phpt',
            'excess_argc_dom_loadhtml_nodelist_30835.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
