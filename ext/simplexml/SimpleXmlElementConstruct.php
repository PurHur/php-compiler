<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\Variable;

/**
 * SimpleXMLElement::__construct — parse data and attach live node state
 * (php-src ext/simplexml/sxe.c; required by #19307 / #19306).
 */
final class SimpleXmlElementConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            throw new \LogicException('SimpleXMLElement::__construct() requires VM context');
        }
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('SimpleXMLElement::__construct() expects at least 1 argument, 0 given');
        }
        $entry = $frame->calledArgs[0]->resolveIndirect()->toObject();
        $dataVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_STRING !== $dataVar->type) {
            throw new \TypeError('SimpleXMLElement::__construct(): Argument #1 ($data) must be of type string');
        }
        VmSimpleXml::constructFromData(
            $frame->vmContext,
            $entry,
            $dataVar->toString(),
            $frame
        );
    }
}
