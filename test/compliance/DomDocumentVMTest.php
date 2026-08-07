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
        yield 'dom_get_elements_by_tag_name_live.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/dom_get_elements_by_tag_name_live.phpt',
            'dom_get_elements_by_tag_name_live.phpt'
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
        yield 'dom_create_element_ns_savexml_xmlns.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_create_element_ns_savexml_xmlns.phpt',
            'dom_create_element_ns_savexml_xmlns.phpt'
        );
        yield 'dom_element_tag_name.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/dom_element_tag_name.phpt',
            'dom_element_tag_name.phpt'
        );
        yield 'dom_namespace_attributes.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/dom_namespace_attributes.phpt',
            'dom_namespace_attributes.phpt'
        );
        if (CompilerVersion::supportsDomNodeIsEqualNode()) {
            yield 'dom_node_is_equal_node.phpt' => self::parsePHPT(
                __DIR__.'/cases/stdlib/dom_node_is_equal_node.phpt',
                'dom_node_is_equal_node.phpt'
            );
        } else {
            yield 'php84_dom_node_is_equal_node_phantom.phpt' => self::parsePHPT(
                __DIR__.'/cases/stdlib/php84_dom_node_is_equal_node_phantom.phpt',
                'php84_dom_node_is_equal_node_phantom.phpt'
            );
        }
        yield 'dom_create_attribute_ns.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/dom_create_attribute_ns.phpt',
            'dom_create_attribute_ns.phpt'
        );
        yield 'dom_create_attribute_ns_no_root.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_create_attribute_ns_no_root.phpt',
            'dom_create_attribute_ns_no_root.phpt'
        );
        yield 'dom_create_attribute_ns_xmlns.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_create_attribute_ns_xmlns.phpt',
            'dom_create_attribute_ns_xmlns.phpt'
        );
        yield 'element_remove_attribute_ns.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/element_remove_attribute_ns.phpt',
            'element_remove_attribute_ns.phpt'
        );
        yield 'element_remove_attribute_ns_return.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/element_remove_attribute_ns_return.phpt',
            'element_remove_attribute_ns_return.phpt'
        );
        yield 'dom_element_setattr_empty_name.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_element_setattr_empty_name.phpt',
            'dom_element_setattr_empty_name.phpt'
        );
        yield 'dom_node_get_line_no.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/dom_node_get_line_no.phpt',
            'dom_node_get_line_no.phpt'
        );
        yield 'dom_node_get_node_path.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/dom_node_get_node_path.phpt',
            'dom_node_get_node_path.phpt'
        );
        yield 'dom_node_get_node_path_sibling_index.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/dom_node_get_node_path_sibling_index.phpt',
            'dom_node_get_node_path_sibling_index.phpt'
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
            yield 'dom_node_get_root_node_detached.phpt' => self::parsePHPT(
                __DIR__.'/cases/stdlib/dom_node_get_root_node_detached.phpt',
                'dom_node_get_root_node_detached.phpt'
            );
        } else {
            yield 'php84_dom_node_get_root_node_phantom.phpt' => self::parsePHPT(
                __DIR__.'/cases/stdlib/php84_dom_node_get_root_node_phantom.phpt',
                'php84_dom_node_get_root_node_phantom.phpt'
            );
        }
        if (CompilerVersion::supportsDomNodeIsConnected()) {
            yield 'dom_node_is_connected.phpt' => self::parsePHPT(
                __DIR__.'/cases/stdlib/dom_node_is_connected.phpt',
                'dom_node_is_connected.phpt'
            );
        } else {
            yield 'php84_dom_node_is_connected_phantom.phpt' => self::parsePHPT(
                __DIR__.'/cases/stdlib/php84_dom_node_is_connected_phantom.phpt',
                'php84_dom_node_is_connected_phantom.phpt'
            );
        }
        if (CompilerVersion::supportsDomNodeCompareDocumentPosition()) {
            yield 'dom_node_compare_document_position.phpt' => self::parsePHPT(
                __DIR__.'/cases/stdlib/dom_node_compare_document_position.phpt',
                'dom_node_compare_document_position.phpt'
            );
        } else {
            yield 'dom_node_compare_document_position_phantom.phpt' => self::parsePHPT(
                __DIR__.'/cases/dom/dom_node_compare_document_position_phantom.phpt',
                'dom_node_compare_document_position_phantom.phpt'
            );
        }
        if (CompilerVersion::supportsDomElementInsertAdjacentHtml()) {
            yield 'dom_element_insert_adjacent_html.phpt' => self::parsePHPT(
                __DIR__.'/cases/stdlib/dom_element_insert_adjacent_html.phpt',
                'dom_element_insert_adjacent_html.phpt'
            );
        } else {
            yield 'php84_dom_element_insert_adjacent_html_phantom.phpt' => self::parsePHPT(
                __DIR__.'/cases/stdlib/php84_dom_element_insert_adjacent_html_phantom.phpt',
                'php84_dom_element_insert_adjacent_html_phantom.phpt'
            );
        }
        if (CompilerVersion::supportsDomElementGetElementsByClassName()) {
            yield 'dom_element_get_elements_by_class_name_85.phpt' => self::parsePHPT(
                __DIR__.'/cases/stdlib/dom_element_get_elements_by_class_name_85.phpt',
                'dom_element_get_elements_by_class_name_85.phpt'
            );
        } else {
            yield 'php84_dom_element_get_elements_by_class_name_phantom.phpt' => self::parsePHPT(
                __DIR__.'/cases/stdlib/php84_dom_element_get_elements_by_class_name_phantom.phpt',
                'php84_dom_element_get_elements_by_class_name_phantom.phpt'
            );
        }
        if (CompilerVersion::supportsDomElementInsertAdjacentElement()) {
            yield 'dom_element_insert_adjacent_element.phpt' => self::parsePHPT(
                __DIR__.'/cases/stdlib/dom_element_insert_adjacent_element.phpt',
                'dom_element_insert_adjacent_element.phpt'
            );
        } else {
            yield 'php84_dom_element_insert_adjacent_element_phantom.phpt' => self::parsePHPT(
                __DIR__.'/cases/stdlib/php84_dom_element_insert_adjacent_element_phantom.phpt',
                'php84_dom_element_insert_adjacent_element_phantom.phpt'
            );
        }
        if (CompilerVersion::supportsDomElementInsertAdjacentText()) {
            yield 'dom_element_insert_adjacent_text.phpt' => self::parsePHPT(
                __DIR__.'/cases/stdlib/dom_element_insert_adjacent_text.phpt',
                'dom_element_insert_adjacent_text.phpt'
            );
        } else {
            yield 'php84_dom_element_insert_adjacent_text_phantom.phpt' => self::parsePHPT(
                __DIR__.'/cases/stdlib/php84_dom_element_insert_adjacent_text_phantom.phpt',
                'php84_dom_element_insert_adjacent_text_phantom.phpt'
            );
        }
        if (CompilerVersion::supportsDomElementInnerOuterHtml()) {
            yield 'dom_element_inner_outer_html.phpt' => self::parsePHPT(
                __DIR__.'/cases/stdlib/dom_element_inner_outer_html.phpt',
                'dom_element_inner_outer_html.phpt'
            );
        } else {
            yield 'php84_dom_element_inner_outer_html_phantom.phpt' => self::parsePHPT(
                __DIR__.'/cases/stdlib/php84_dom_element_inner_outer_html_phantom.phpt',
                'php84_dom_element_inner_outer_html_phantom.phpt'
            );
        }
        if (CompilerVersion::supportsDomElementToggleAttribute()) {
            yield 'dom_element_toggle_attribute.phpt' => self::parsePHPT(
                __DIR__.'/cases/stdlib/dom_element_toggle_attribute.phpt',
                'dom_element_toggle_attribute.phpt'
            );
        } else {
            yield 'php83_dom_element_toggle_attribute_phantom.phpt' => self::parsePHPT(
                __DIR__.'/cases/stdlib/php83_dom_element_toggle_attribute_phantom.phpt',
                'php83_dom_element_toggle_attribute_phantom.phpt'
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
        yield 'dom_living_register_node_class.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_living_register_node_class.phpt',
            'dom_living_register_node_class.phpt'
        );
        yield 'dom_html_createempty_empty.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_html_createempty_empty.phpt',
            'dom_html_createempty_empty.phpt'
        );
        yield 'dom_html_createfromstring_doctype_null.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_html_createfromstring_doctype_null.phpt',
            'dom_html_createfromstring_doctype_null.phpt'
        );
        yield 'dom_createfromstring_reflection.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_createfromstring_reflection.phpt',
            'dom_createfromstring_reflection.phpt'
        );
        yield 'dom_createfromstring_named.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_createfromstring_named.phpt',
            'dom_createfromstring_named.phpt'
        );
        yield 'dom_createfromfile_reflection.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_createfromfile_reflection.phpt',
            'dom_createfromfile_reflection.phpt'
        );
        yield 'dom_createfromfile_named.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_createfromfile_named.phpt',
            'dom_createfromfile_named.phpt'
        );
        yield 'dom_document_instance_reflection.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_document_instance_reflection.phpt',
            'dom_document_instance_reflection.phpt'
        );
        yield 'dom_document_schema_validate_arity_message.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_document_schema_validate_arity_message.phpt',
            'dom_document_schema_validate_arity_message.phpt'
        );
        yield 'dom_document_schema_validate_warning.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_document_schema_validate_warning.phpt',
            'dom_document_schema_validate_warning.phpt'
        );
        yield 'dom_document_validate_source.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_document_validate_source.phpt',
            'dom_document_validate_source.phpt'
        );
        yield 'dom_document_schema_validate_source_ok.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_document_schema_validate_source_ok.phpt',
            'dom_document_schema_validate_source_ok.phpt'
        );
        yield 'dom_document_validate.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_document_validate.phpt',
            'dom_document_validate.phpt'
        );
        yield 'dom_document_save.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_document_save.phpt',
            'dom_document_save.phpt'
        );
        yield 'dom_savexml_doctype_internal_subset.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_savexml_doctype_internal_subset.phpt',
            'dom_savexml_doctype_internal_subset.phpt'
        );
        yield 'dom_fragment_appendxml_warnings.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/dom_fragment_appendxml_warnings.phpt',
            'dom_fragment_appendxml_warnings.phpt'
        );
        yield 'dom_loadhtml_unclosed_warnings.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/dom_loadhtml_unclosed_warnings.phpt',
            'dom_loadhtml_unclosed_warnings.phpt'
        );
        yield 'dom_loadhtml_unclosed_nonoptional.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_loadhtml_unclosed_nonoptional.phpt',
            'dom_loadhtml_unclosed_nonoptional.phpt'
        );
        yield 'dom_loadhtml_bare_utf8.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_loadhtml_bare_utf8.phpt',
            'dom_loadhtml_bare_utf8.phpt'
        );
        yield 'dom_loadhtml_xml_encoding_prologue.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_loadhtml_xml_encoding_prologue.phpt',
            'dom_loadhtml_xml_encoding_prologue.phpt'
        );
        yield 'dom_loadxml_invalid_warning.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/dom_loadxml_invalid_warning.phpt',
            'dom_loadxml_invalid_warning.phpt'
        );
        yield 'dom_loadxml_comment.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/dom_loadxml_comment.phpt',
            'dom_loadxml_comment.phpt'
        );
        yield 'dom_loadxml_preamble_comment_pi.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_loadxml_preamble_comment_pi.phpt',
            'dom_loadxml_preamble_comment_pi.phpt'
        );
        yield 'dom_loadxml_attlist_defaults.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_loadxml_attlist_defaults.phpt',
            'dom_loadxml_attlist_defaults.phpt'
        );
        yield 'dom_load_empty_source.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/dom_load_empty_source.phpt',
            'dom_load_empty_source.phpt'
        );
        yield 'dom_namednodemap.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/dom_namednodemap.phpt',
            'dom_namednodemap.phpt'
        );
        yield 'dom_namednodemap_get_named_item_ns.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_namednodemap_get_named_item_ns.phpt',
            'dom_namednodemap_get_named_item_ns.phpt'
        );
        yield 'dom_namednodemap_foreach_keys.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_namednodemap_foreach_keys.phpt',
            'dom_namednodemap_foreach_keys.phpt'
        );
        yield 'dom_namednodemap_live_foreach_remove.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_namednodemap_live_foreach_remove.phpt',
            'dom_namednodemap_live_foreach_remove.phpt'
        );
        yield 'dom_element_xmlns_attributes_namednodemap.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_element_xmlns_attributes_namednodemap.phpt',
            'dom_element_xmlns_attributes_namednodemap.phpt'
        );
        yield 'dom_text_nodes.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/dom_text_nodes.phpt',
            'dom_text_nodes.phpt'
        );
        yield 'dom_character_data_length.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_character_data_length.phpt',
            'dom_character_data_length.phpt'
        );
        yield 'dom_character_data_data_write.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_character_data_data_write.phpt',
            'dom_character_data_data_write.phpt'
        );
        yield 'dom_character_data_index_size_error.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_character_data_index_size_error.phpt',
            'dom_character_data_index_size_error.phpt'
        );
        yield 'dom_childnodes_empty.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_childnodes_empty.phpt',
            'dom_childnodes_empty.phpt'
        );
        yield 'dom_node_insertbefore_property_fetch_ref.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_node_insertbefore_property_fetch_ref.phpt',
            'dom_node_insertbefore_property_fetch_ref.phpt'
        );
        yield 'dom_node_insertbefore_live_childnodes_temps.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_node_insertbefore_live_childnodes_temps.phpt',
            'dom_node_insertbefore_live_childnodes_temps.phpt'
        );
        yield 'dom_node_insertbefore_self_ref.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_node_insertbefore_self_ref.phpt',
            'dom_node_insertbefore_self_ref.phpt'
        );
        yield 'dom_node_replacechild_live_childnodes_temps.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_node_replacechild_live_childnodes_temps.phpt',
            'dom_node_replacechild_live_childnodes_temps.phpt'
        );
        yield 'dom_node_replacechild_identity.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_node_replacechild_identity.phpt',
            'dom_node_replacechild_identity.phpt'
        );
        yield 'dom_getelementbyid_replacechild_same_id.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_getelementbyid_replacechild_same_id.phpt',
            'dom_getelementbyid_replacechild_same_id.phpt'
        );
        yield 'dom_getelementbyid_duplicate_setidattribute.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_getelementbyid_duplicate_setidattribute.phpt',
            'dom_getelementbyid_duplicate_setidattribute.phpt'
        );
        yield 'dom_node_replacewith_middle_string_literal.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_node_replacewith_middle_string_literal.phpt',
            'dom_node_replacewith_middle_string_literal.phpt'
        );
        yield 'dom_import_node_chained_deep.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/dom_import_node_chained_deep.phpt',
            'dom_import_node_chained_deep.phpt'
        );
        yield 'dom_import_node_methodcall_chained_deep.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/dom_import_node_methodcall_chained_deep.phpt',
            'dom_import_node_methodcall_chained_deep.phpt'
        );
        yield 'dom_node_nested_clonenode_bool_literal.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_node_nested_clonenode_bool_literal.phpt',
            'dom_node_nested_clonenode_bool_literal.phpt'
        );
        yield 'dom_node_c14n_unattached_clone.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_node_c14n_unattached_clone.phpt',
            'dom_node_c14n_unattached_clone.phpt'
        );
        yield 'dom_node_c14n_xpath_nodeset.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_node_c14n_xpath_nodeset.phpt',
            'dom_node_c14n_xpath_nodeset.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
