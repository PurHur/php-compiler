<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xml;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * xml extension module entry (php-src ext/xml/xml.c; issue #7406).
 *
 * SAX/expat parity tracked in #3494; v1 skeleton enables function_exists() and inventory.
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
        return [
            new xml_parser_create(),
            new xml_parse(),
            new xml_parser_free(),
        ];
    }
}
