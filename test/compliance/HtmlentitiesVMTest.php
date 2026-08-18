<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for htmlentities() / html_entity_decode() (#2472). */
final class HtmlentitiesVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        foreach ([
            'htmlentities.phpt',
            'htmlentities_reflection_defaults.phpt',
            'html_entity_decode.phpt',
            'html_entity_decode_ent_html5.phpt',
            'html_entity_decode_after_hs_decode.phpt',
        ] as $file) {
            yield $file => self::parsePHPT(
                __DIR__.'/cases/stdlib/'.$file,
                $file
            );
        }
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
