<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/AotTest.php';

/**
 * Minimal AOT fixture for Router::renderApiStatus() parity (#849, #1820).
 *
 * @group llvm
 * @group aot
 * @group aot-link
 * @group miniwebapp-aot-execute
 */
final class ClassMethodJsonApiAotTest extends AotTest
{
    public static function providePHPTests(): \Generator
    {
        $path = dirname(__DIR__).'/fixtures/aot/cases/class_method_json_api.phpt';
        if (!is_file($path)) {
            throw new \RuntimeException('class_method_json_api: missing fixture '.$path);
        }
        yield 'class_method_json_api' => self::parsePHPT($path, 'class_method_json_api.phpt');
    }
}
