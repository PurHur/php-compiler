<?php

declare(strict_types=1);

namespace PHPCompiler\ext\libxml;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;
use PHPCompiler\VM;

/**
 * libxml extension module entry (php-src ext/libxml/libxml.c; issue #6058).
 *
 * PHP-in-PHP error buffer for DOM/SimpleXML loaders — no runtime/*.c growth.
 */
class Module extends ModuleAbstract
{
    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        BuiltinClasses::register($runtime->vmContext);
        foreach (LibxmlConstants::registeredConstants() as $name => $value) {
            $var = new VM\Variable();
            if (\is_string($value)) {
                $var->string($value);
            } else {
                $var->int($value);
            }
            $runtime->vmContext->defineConstant($name, $var);
        }
    }

    public function getFunctions(): array
    {
        return [
            new libxml_use_internal_errors(),
            new libxml_get_errors(),
            new libxml_get_last_error(),
            new libxml_clear_errors(),
            new libxml_set_streams_context(),
            new libxml_disable_entity_loader(),
            new libxml_set_external_entity_loader(),
            new libxml_get_external_entity_loader(),
        ];
    }
}
