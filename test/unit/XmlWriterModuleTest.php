<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\ModuleRegistry;
use PHPCompiler\ext\standard\VmReflection;
use PHPUnit\Framework\TestCase;

/**
 * xmlwriter extension module registration (issue #6065).
 *
 * @group xmlwriter_module
 */
final class XmlWriterModuleTest extends TestCase
{
    public function test_xmlwriter_module_registers_class(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;

        self::assertTrue(VmReflection::classExists($ctx, 'XMLWriter'));
        self::assertTrue(ModuleRegistry::extensionLoaded('xmlwriter'));

        $code = <<<'PHP'
<?php
echo (int) class_exists('XMLWriter', false);
PHP;
        $block = $runtime->parseAndCompile($code, 'xmlwriter_module.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('1', ob_get_clean());
    }

    public function test_xmlwriter_open_memory_matches_zend_shape(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$w = new XMLWriter();
$w->openMemory();
$w->startDocument('1.0', 'UTF-8');
$w->startElement('root');
$w->text('hi');
$w->endElement();
echo $w->outputMemory();
PHP;
        $block = $runtime->parseAndCompile($code, 'xmlwriter_memory.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<root>hi</root>", ob_get_clean());
    }

    public function test_xmlwriter_procedural_open_memory_matches_zend_shape(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$w = xmlwriter_open_memory();
xmlwriter_start_document($w, '1.0');
xmlwriter_start_element($w, 'root');
xmlwriter_write_attribute($w, 'id', '1');
xmlwriter_text($w, 'hi');
xmlwriter_end_element($w);
xmlwriter_end_document($w);
echo xmlwriter_output_memory($w);
PHP;
        $block = $runtime->parseAndCompile($code, 'xmlwriter_procedural.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("<?xml version=\"1.0\"?>\n<root id=\"1\">hi</root>\n", ob_get_clean());
    }

    public function test_xmlwriter_text_rejects_object_operand(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$w = new XMLWriter();
$w->openMemory();
$w->startDocument();
$w->startElement('root');
try {
    $w->text(new stdClass());
    echo "no_error\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
PHP;
        $block = $runtime->parseAndCompile($code, 'xmlwriter_text_type.php');
        ob_start();
        $runtime->run($block);
        self::assertSame(
            "XMLWriter::text(): Argument #1 (\$content) must be of type string, stdClass given\n",
            ob_get_clean()
        );
    }
}
