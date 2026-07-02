<?php

declare(strict_types=1);

namespace PHPCompiler\ext\libxml;

use PHPCompiler\Frame;

/** libxml_get_external_entity_loader() — return custom loader or null (#14953, php-src ext/libxml/libxml.c). */
final class libxml_get_external_entity_loader extends LibxmlFunction
{
    public function __construct()
    {
        parent::__construct('libxml_get_external_entity_loader');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 0) {
            throw new \ArgumentCountError(
                'libxml_get_external_entity_loader() expects exactly 0 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $loader = VmLibxml::getExternalEntityLoader();
        if (null === $loader) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->copyFrom($loader);
    }
}
