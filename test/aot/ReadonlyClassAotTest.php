<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/AotTest.php';

/**
 * Readonly class enforcement in native AOT binaries (#4082, phase 2 of #1360).
 *
 * php-src: Zend/zend_object_handlers.c zend_std_write_property
 *
 * @group llvm
 * @group aot
 * @group aot-link
 */
final class ReadonlyClassAotTest extends AotTest
{
    public static function providePHPTests(): \Generator
    {
        $path = dirname(__DIR__).'/fixtures/aot/cases/readonly_class_write.phpt';
        if (!is_file($path)) {
            throw new \RuntimeException('readonly class AOT: missing fixture '.$path);
        }
        yield 'readonly_class_write' => self::parsePHPT($path, 'readonly_class_write.phpt');
    }
}
