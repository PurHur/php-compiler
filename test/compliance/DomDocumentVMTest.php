<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for ext/dom DOMDocument tree APIs (#14335, #14336). */
final class DomDocumentVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_node_tree_nav.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/dom_node_tree_nav.phpt',
            'dom_node_tree_nav.phpt'
        );
        yield 'dom_get_elements_by_tag_name.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/dom_get_elements_by_tag_name.phpt',
            'dom_get_elements_by_tag_name.phpt'
        );
        yield 'dom_get_elements_by_tag_name_local.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/dom_get_elements_by_tag_name_local.phpt',
            'dom_get_elements_by_tag_name_local.phpt'
        );
        yield 'domdocument_loadxml.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/domdocument_loadxml.phpt',
            'domdocument_loadxml.phpt'
        );
        yield 'domdocument_loadxml_concat.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/domdocument_loadxml_concat.phpt',
            'domdocument_loadxml_concat.phpt'
        );
        yield 'dom_node_base_uri.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/dom_node_base_uri.phpt',
            'dom_node_base_uri.phpt'
        );
        yield 'dom_create_element_ns.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/dom_create_element_ns.phpt',
            'dom_create_element_ns.phpt'
        );
        yield 'dom_namespace_attributes.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/dom_namespace_attributes.phpt',
            'dom_namespace_attributes.phpt'
        );
        yield 'dom_node_is_equal_node.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/dom_node_is_equal_node.phpt',
            'dom_node_is_equal_node.phpt'
        );
        yield 'dom_create_attribute_ns.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/dom_create_attribute_ns.phpt',
            'dom_create_attribute_ns.phpt'
        );
        yield 'element_remove_attribute_ns.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/element_remove_attribute_ns.phpt',
            'element_remove_attribute_ns.phpt'
        );
        yield 'element_remove_attribute_ns_return.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/element_remove_attribute_ns_return.phpt',
            'element_remove_attribute_ns_return.phpt'
        );
        yield 'dom_node_get_line_no.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/dom_node_get_line_no.phpt',
            'dom_node_get_line_no.phpt'
        );
        yield 'dom_node_get_node_path.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/dom_node_get_node_path.phpt',
            'dom_node_get_node_path.phpt'
        );
        yield 'dom_node_append_prepend.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/dom_node_append_prepend.phpt',
            'dom_node_append_prepend.phpt'
        );
        yield 'dom_document_append_prepend.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/dom_document_append_prepend.phpt',
            'dom_document_append_prepend.phpt'
        );
        if (CompilerVersion::supportsDomNodeContains()) {
            yield 'dom_node_contains.phpt' => self::parsePHPT(
                __DIR__.'/cases/stdlib/dom_node_contains.phpt',
                'dom_node_contains.phpt'
            );
        } else {
            yield 'php84_dom_node_contains_phantom.phpt' => self::parsePHPT(
                __DIR__.'/cases/stdlib/php84_dom_node_contains_phantom.phpt',
                'php84_dom_node_contains_phantom.phpt'
            );
        }
        if (CompilerVersion::supportsDomNodeGetRootNode()) {
            yield 'dom_node_get_root_node.phpt' => self::parsePHPT(
                __DIR__.'/cases/stdlib/dom_node_get_root_node.phpt',
                'dom_node_get_root_node.phpt'
            );
        } else {
            yield 'php84_dom_node_get_root_node_phantom.phpt' => self::parsePHPT(
                __DIR__.'/cases/stdlib/php84_dom_node_get_root_node_phantom.phpt',
                'php84_dom_node_get_root_node_phantom.phpt'
            );
        }
        if (CompilerVersion::supportsDomNodeCompareDocumentPosition()) {
            yield 'dom_node_compare_document_position.phpt' => self::parsePHPT(
                __DIR__.'/cases/stdlib/dom_node_compare_document_position.phpt',
                'dom_node_compare_document_position.phpt'
            );
        } else {
            yield 'php84_dom_node_compare_document_position_phantom.phpt' => self::parsePHPT(
                __DIR__.'/cases/stdlib/php84_dom_node_compare_document_position_phantom.phpt',
                'php84_dom_node_compare_document_position_phantom.phpt'
            );
        }
        yield 'dom_exception.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/dom_exception.phpt',
            'dom_exception.phpt'
        );
        yield 'dom_register_node_class.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/dom_register_node_class.phpt',
            'dom_register_node_class.phpt'
        );
        yield 'dom_document_schema_validate_warning.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_document_schema_validate_warning.phpt',
            'dom_document_schema_validate_warning.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
