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
    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        BuiltinClasses::register($runtime->vmContext);
    }

    public function getFunctions(): array
    {
        // Procedural aliases mirror implemented OOP methods (php-src php_xmlwriter.c; #19514).
        // Remaining stub aliases (DTD/start_* NS pairs) tracked in sibling xmlwriter issues.
        return [
            new xmlwriter_open_memory(),
            new xmlwriter_open_uri(),
            new xmlwriter_set_indent(),
            new xmlwriter_set_indent_string(),
            new xmlwriter_start_document(),
            new xmlwriter_end_document(),
            new xmlwriter_start_element(),
            new xmlwriter_end_element(),
            new xmlwriter_full_end_element(),
            new xmlwriter_write_attribute(),
            new xmlwriter_start_attribute(),
            new xmlwriter_end_attribute(),
            new xmlwriter_write_element(),
            new xmlwriter_write_cdata(),
            new xmlwriter_write_comment(),
            new xmlwriter_text(),
            new xmlwriter_output_memory(),
            new xmlwriter_flush(),
        ];
    }
}
