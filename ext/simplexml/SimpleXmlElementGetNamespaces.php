<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\Variable;

/** SimpleXMLElement::getNamespaces — namespaces in use (php-src sxe_add_namespaces; #22729). */
final class SimpleXmlElementGetNamespaces extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getNamespaces');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('SimpleXMLElement::getNamespaces() called without $this');
        }
        // php-src simplexml.stub.php: getNamespaces(bool $recursive = false): array (#30828).
        $this->requireAtMostUserArgCount($frame, 'SimpleXMLElement::getNamespaces', 1);
        $entry = VmSimpleXml::requireElement(
            $frame->calledArgs[0]->resolveIndirect()->toObject(),
            'SimpleXMLElement::getNamespaces()'
        );
        $recursive = false;
        if (\count($frame->calledArgs) >= 2) {
            $arg = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $arg->type) {
                throw new \TypeError('SimpleXMLElement::getNamespaces(): Argument #1 ($recursive) must be of type bool');
            }
            $recursive = $arg->toBool();
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->array(VmSimpleXml::getNamespaces($entry, $recursive));
        }
    }
}
