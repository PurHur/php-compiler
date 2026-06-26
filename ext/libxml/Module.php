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
        foreach ([
            'LIBXML_ERR_NONE' => LibxmlConstants::LIBXML_ERR_NONE,
            'LIBXML_ERR_WARNING' => LibxmlConstants::LIBXML_ERR_WARNING,
            'LIBXML_ERR_ERROR' => LibxmlConstants::LIBXML_ERR_ERROR,
            'LIBXML_ERR_FATAL' => LibxmlConstants::LIBXML_ERR_FATAL,
        ] + LibxmlConstants::parseFlagConstants() as $name => $value) {
            $var = new VM\Variable();
            $var->int($value);
            $runtime->vmContext->defineConstant($name, $var);
        }
    }

    public function getFunctions(): array
    {
        return [
            new libxml_use_internal_errors(),
            new libxml_get_errors(),
            new libxml_clear_errors(),
        ];
    }
}
