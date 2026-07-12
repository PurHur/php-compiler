<?php

declare(strict_types=1);

namespace PHPCompiler\ext\libxml;

use PHPCompiler\ext\standard\VmEngineBuiltinDeprecation;
use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** libxml_disable_entity_loader() — toggle external entity expansion (#6379, php-src ext/libxml/libxml.c). */
final class libxml_disable_entity_loader extends LibxmlFunction
{
    public function __construct()
    {
        parent::__construct('libxml_disable_entity_loader');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(
                'libxml_disable_entity_loader() expects at most 1 argument, '.$argc.' given'
            );
        }
        VmEngineBuiltinDeprecation::emitFunction($frame, 'libxml_disable_entity_loader');
        $disable = true;
        if (1 === $argc) {
            $arg = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN === $arg->type) {
                $disable = $arg->toBool();
            } else {
                $disable = (bool) $arg->toInt();
            }
        }
        if (null === $frame->returnVar) {
            VmLibxml::disableEntityLoader($disable);

            return;
        }
        $frame->returnVar->bool(VmLibxml::disableEntityLoader($disable));
    }
}
