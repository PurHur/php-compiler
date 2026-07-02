<?php

declare(strict_types=1);

namespace PHPCompiler\ext\libxml;

use PHPCompiler\Frame;
use PHPCompiler\ext\standard\VmReflection;

/** libxml_set_external_entity_loader() — register custom external entity loader (#6379, php-src ext/libxml/libxml.c). */
final class libxml_set_external_entity_loader extends LibxmlFunction
{
    public function __construct()
    {
        parent::__construct('libxml_set_external_entity_loader');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'libxml_set_external_entity_loader() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $ctx = VmReflection::requireContext($frame);
        VmLibxml::setExternalEntityLoader($ctx, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }
}
