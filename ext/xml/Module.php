<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xml;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * xml extension module entry (php-src ext/xml/xml.c; issue #7406).
 *
 * SAX/expat parity tracked in #3494; namespace parsers via xml_parser_create_ns (#19683).
 */
class Module extends ModuleAbstract
{

    /**
     * php-src ext/xml builds on ext/libxml (libxml2).
     *
     * Runtime::loadCoreModules() already loads them in this order; declaring it makes the
     * constraint checkable instead of remembered (RELEASE-PLAN Phase 2.5).
     *
     * @return list<string>
     */
    public function getExtensionDependencies(): array
    {
        return ['libxml'];
    }
    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        BuiltinClasses::register($runtime->vmContext);
        foreach (XmlConstants::registeredConstants() as $name => $value) {
            $var = new \PHPCompiler\VM\Variable();
            if (\is_int($value)) {
                $var->int($value);
            } else {
                $var->string((string) $value);
            }
            $runtime->vmContext->defineConstant($name, $var);
        }
    }

    public function getFunctions(): array
    {
        return [
            new xml_parser_create(),
            new xml_parser_create_ns(),
            new xml_parse(),
            new xml_parser_free(),
            new xml_get_error_code(),
            new xml_error_string(),
            new xml_get_current_line_number(),
            new xml_get_current_column_number(),
            new xml_get_current_byte_index(),
            new xml_parse_into_struct(),
            new xml_set_element_handler(),
            new xml_set_character_data_handler(),
            new xml_set_default_handler(),
            new xml_set_processing_instruction_handler(),
            new xml_set_unparsed_entity_decl_handler(),
            new xml_set_notation_decl_handler(),
            new xml_set_external_entity_ref_handler(),
            new xml_set_start_namespace_decl_handler(),
            new xml_set_end_namespace_decl_handler(),
            new xml_set_object(),
            new xml_parser_set_option(),
            new xml_parser_get_option(),
        ];
    }
}
