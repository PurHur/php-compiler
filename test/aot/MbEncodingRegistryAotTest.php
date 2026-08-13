<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/AotTest.php';

/**
 * AOT: mb_encoding_aliases()/mb_list_encodings() registry fold (#30795).
 *
 * @group llvm
 * @group aot
 * @group aot-link
 */
final class MbEncodingRegistryAotTest extends AotTest
{
    public static function providePHPTests(): \Generator
    {
        $basename = 'mb_encoding_registry.phpt';
        $path = dirname(__DIR__).'/fixtures/aot/cases/'.$basename;
        if (!is_file($path)) {
            throw new \RuntimeException('mb encoding registry AOT: missing fixture '.$path);
        }
        yield pathinfo($basename, PATHINFO_FILENAME) => self::parsePHPT($path, $basename);
    }
}
