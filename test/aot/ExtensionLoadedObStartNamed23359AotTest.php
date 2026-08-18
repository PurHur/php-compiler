<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/AotTest.php';

/**
 * AOT: extension_loaded(extension:) / ob_start(callback:) named args (#23359).
 *
 * @group llvm
 * @group aot
 * @group aot-link
 */
final class ExtensionLoadedObStartNamed23359AotTest extends AotTest
{
    public static function providePHPTests(): \Generator
    {
        $basename = 'extension_loaded_ob_start_named_params.phpt';
        $path = dirname(__DIR__).'/fixtures/aot/cases/'.$basename;
        if (!is_file($path)) {
            throw new \RuntimeException('extension_loaded/ob_start named AOT: missing fixture '.$path);
        }
        yield pathinfo($basename, PATHINFO_FILENAME) => self::parsePHPT($path, $basename);
    }
}
