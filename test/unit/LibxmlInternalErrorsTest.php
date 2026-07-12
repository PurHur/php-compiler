<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\libxml\LibxmlConstants;
use PHPCompiler\ext\libxml\VmLibxml;
use PHPCompiler\ext\standard\VmReflection;
use PHPUnit\Framework\TestCase;

/**
 * libxml internal error buffer module (issue #6058).
 *
 * @group libxml_internal_errors
 */
final class LibxmlInternalErrorsTest extends TestCase
{
    public function test_libxml_module_registers_functions_class_and_extension(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;

        foreach (['libxml_use_internal_errors', 'libxml_get_errors', 'libxml_get_last_error', 'libxml_clear_errors', 'libxml_set_streams_context', 'libxml_disable_entity_loader', 'libxml_set_external_entity_loader', 'libxml_get_external_entity_loader'] as $fn) {
            self::assertTrue(VmReflection::functionExists($ctx, $fn), $fn);
        }
        self::assertTrue(VmReflection::classExists($ctx, 'LibXMLError'));

        $code = <<<'PHP'
<?php
echo (int) function_exists('libxml_use_internal_errors');
echo (int) function_exists('libxml_get_errors');
echo (int) function_exists('libxml_get_last_error');
echo (int) function_exists('libxml_clear_errors');
echo (int) function_exists('libxml_set_streams_context');
echo (int) function_exists('libxml_disable_entity_loader');
echo (int) function_exists('libxml_set_external_entity_loader');
echo (int) function_exists('libxml_get_external_entity_loader');
echo (int) class_exists('LibXMLError');
echo (int) extension_loaded('libxml');
PHP;
        $block = $runtime->parseAndCompile($code, 'libxml_module.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('1111111111', ob_get_clean());
    }

    public function test_libxml_get_last_error_returns_tail_or_false(): void
    {
        VmLibxml::clearErrors();
        VmLibxml::useInternalErrors(true);

        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
libxml_use_internal_errors(true);
libxml_clear_errors();
echo (int) (false === libxml_get_last_error()), "\n";
$parser = xml_parser_create();
xml_parse($parser, '<broken', true);
$last = libxml_get_last_error();
echo is_object($last) ? get_class($last)."\n" : "notobj\n";
echo $last->code > 0 ? "code\n" : "nocode\n";
$errors = libxml_get_errors();
echo $last->code === $errors[0]->code ? "match\n" : "nomatch\n";
libxml_clear_errors();
echo (int) (false === libxml_get_last_error()), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'libxml_last_error.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("1\nLibXMLError\ncode\nmatch\n1\n", ob_get_clean());
    }

    public function test_internal_errors_buffer_malformed_xml_via_xml_parse(): void
    {
        VmLibxml::clearErrors();
        VmLibxml::useInternalErrors(false);

        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
libxml_use_internal_errors(true);
$parser = xml_parser_create();
xml_parse($parser, '<unclosed', true);
$errors = libxml_get_errors();
echo count($errors), "\n";
echo $errors[0]->code > 0 ? "code\n" : "nocode\n";
echo $errors[0]->level === LIBXML_ERR_FATAL ? "level\n" : "nolevel\n";
libxml_clear_errors();
echo count(libxml_get_errors()), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'libxml_buffer.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("1\ncode\nlevel\n0\n", ob_get_clean());
    }

    public function test_internal_errors_buffer_malformed_xml_via_dom_loadxml(): void
    {
        VmLibxml::clearErrors();
        VmLibxml::useInternalErrors(false);

        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
libxml_use_internal_errors(true);
libxml_clear_errors();
$doc = new DOMDocument();
$ok = $doc->loadXML('<root><unclosed');
echo $ok ? "loaded\n" : "failed\n";
$errors = libxml_get_errors();
echo count($errors), "\n";
echo $errors[0]->level === LIBXML_ERR_FATAL ? "fatal\n" : "nofatal\n";
echo $errors[0]->code === 73 ? "code73\n" : "nocode73\n";
$msg = $errors[0]->message;
echo str_contains($msg, "Couldn't find end of Start Tag unclosed") ? "message\n" : "nomessage\n";
libxml_clear_errors();
echo count(libxml_get_errors()), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'libxml_dom_loadxml.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("failed\n2\nfatal\ncode73\nmessage\n0\n", ob_get_clean());
    }

    public function test_libxml_set_streams_context_accepts_stream_context(): void
    {
        VmLibxml::clearErrors();
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$ctx = stream_context_create(['http' => ['timeout' => 2]]);
libxml_set_streams_context($ctx);
echo "ok\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'libxml_streams_context.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("ok\n", ob_get_clean());
    }

    public function test_no_runtime_c_growth_for_libxml(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/AOT/runtime/libxml.c');
        $this->assertFileDoesNotExist(__DIR__.'/../../runtime/libxml.c');
        $linker = (string) file_get_contents(__DIR__.'/../../lib/AOT/Linker.php');
        $this->assertStringNotContainsString('libxml', $linker);
    }

    public function test_libxml_error_object_fields(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        $var = VmLibxml::createErrorObject($ctx, [
            'level' => LibxmlConstants::LIBXML_ERR_WARNING,
            'code' => 42,
            'column' => 3,
            'message' => 'test message',
            'file' => 'probe.xml',
            'line' => 9,
        ]);
        $obj = $var->toObject();
        self::assertSame(1, $obj->getProperty('level')->toInt());
        self::assertSame(42, $obj->getProperty('code')->toInt());
        self::assertSame(3, $obj->getProperty('column')->toInt());
        self::assertSame('test message', $obj->getProperty('message')->toString());
        self::assertSame('probe.xml', $obj->getProperty('file')->toString());
        self::assertSame(9, $obj->getProperty('line')->toInt());
    }
}
