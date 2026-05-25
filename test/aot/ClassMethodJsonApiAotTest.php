<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/AotTest.php';

/**
 * Minimal AOT execute gate for Router::renderApiStatus()-style JSON (#849, #1820).
 *
 * Flat control: test/fixtures/aot/cases/json_encode_api.phpt
 * 003 route: test/unit/MiniWebAppAotExecuteTest.php (api/status, #1529)
 *
 * @group llvm
 * @group aot
 * @group aot-link
 * @group miniwebapp
 */
final class ClassMethodJsonApiAotTest extends AotTest
{
    public static function providePHPTests(): \Generator
    {
        $path = dirname(__DIR__).'/fixtures/aot/cases/class_method_json_api.phpt';
        if (!is_file($path)) {
            throw new \RuntimeException("class-method-json-api: missing fixture {$path}");
        }
        yield 'class_method_json_api' => self::parsePHPT($path, 'class_method_json_api.phpt');
    }
}
