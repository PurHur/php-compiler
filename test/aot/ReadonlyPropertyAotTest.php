<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/AotTest.php';

/**
 * Per-property readonly enforcement in native AOT binaries (#3149).
 *
 * php-src: Zend/zend_object_handlers.c zend_std_write_property
 *
 * @group llvm
 * @group aot
 * @group aot-link
 */
final class ReadonlyPropertyAotTest extends AotTest
{
    public static function providePHPTests(): \Generator
    {
        foreach (['readonly_property_write.phpt', 'readonly_property_unset.phpt', 'readonly_property_inc.phpt', 'readonly_property_dec.phpt', 'readonly_property_compound_assign.phpt', 'readonly_property_promoted.phpt'] as $basename) {
            $path = dirname(__DIR__).'/fixtures/aot/cases/'.$basename;
            if (!is_file($path)) {
                throw new \RuntimeException('readonly property AOT: missing fixture '.$path);
            }
            yield pathinfo($basename, PATHINFO_FILENAME) => self::parsePHPT($path, $basename);
        }
    }
}
