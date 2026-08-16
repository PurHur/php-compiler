<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: __phpcDom* engine props are PHP-invisible (#31439). */
final class DomPhpcEnginePropsInvisibleVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_phpc_engine_props_invisible.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_phpc_engine_props_invisible.phpt',
            'dom_phpc_engine_props_invisible.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
