<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * xmlwriter extension module entry (php-src ext/xmlwriter/php_xmlwriter.c; issue #6065).
 *
 * PHP-in-PHP streaming writer — no runtime/*.c growth.
 */
class Module extends ModuleAbstract
{

    /**
     * php-src ext/xmlwriter builds on ext/libxml (libxml2).
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
    }

    public function getFunctions(): array
    {
        // Procedural aliases mirror implemented OOP methods (php-src php_xmlwriter.c; #19514, #20049, #20320, #20322).
        return [
            new xmlwriter_open_memory(),
            new xmlwriter_open_uri(),
            new xmlwriter_set_indent(),
            new xmlwriter_set_indent_string(),
            new xmlwriter_start_document(),
            new xmlwriter_end_document(),
            new xmlwriter_start_element(),
            new xmlwriter_start_element_ns(),
            new xmlwriter_end_element(),
            new xmlwriter_full_end_element(),
            new xmlwriter_write_attribute(),
            new xmlwriter_write_attribute_ns(),
            new xmlwriter_start_attribute(),
            new xmlwriter_start_attribute_ns(),
            new xmlwriter_end_attribute(),
            new xmlwriter_write_element(),
            new xmlwriter_write_element_ns(),
            new xmlwriter_write_cdata(),
            new xmlwriter_start_cdata(),
            new xmlwriter_end_cdata(),
            new xmlwriter_write_comment(),
            new xmlwriter_start_comment(),
            new xmlwriter_end_comment(),
            new xmlwriter_write_raw(),
            new xmlwriter_write_pi(),
            new xmlwriter_start_pi(),
            new xmlwriter_end_pi(),
            new xmlwriter_write_dtd(),
            new xmlwriter_start_dtd(),
            new xmlwriter_end_dtd(),
            new xmlwriter_write_dtd_element(),
            new xmlwriter_write_dtd_attlist(),
            new xmlwriter_start_dtd_entity(),
            new xmlwriter_end_dtd_entity(),
            new xmlwriter_write_dtd_entity(),
            new xmlwriter_text(),
            new xmlwriter_start_dtd_attlist(),
            new xmlwriter_end_dtd_attlist(),
            new xmlwriter_start_dtd_element(),
            new xmlwriter_end_dtd_element(),
            new xmlwriter_output_memory(),
            new xmlwriter_flush(),
        ];
    }
}
