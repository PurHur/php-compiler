<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\Variable;

/** SimpleXMLElement::getDocNamespaces — xmlns map (php-src ext/simplexml/sxe.c; #18038). */
final class SimpleXmlElementGetDocNamespaces extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getDocNamespaces');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('SimpleXMLElement::getDocNamespaces() called without $this');
        }
        // php-src simplexml.stub.php: getDocNamespaces(bool $recursive = false, bool $fromRoot = true) (#30828).
        $this->requireAtMostUserArgCount($frame, 'SimpleXMLElement::getDocNamespaces', 2);
        $entry = VmSimpleXml::requireElement(
            $frame->calledArgs[0]->resolveIndirect()->toObject(),
            'SimpleXMLElement::getDocNamespaces()'
        );
        $recursive = false;
        $fromRoot = true;
        if (\count($frame->calledArgs) >= 2) {
            $arg = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $arg->type) {
                throw new \TypeError('SimpleXMLElement::getDocNamespaces(): Argument #1 ($recursive) must be of type bool');
            }
            $recursive = $arg->toBool();
        }
        if (\count($frame->calledArgs) >= 3) {
            $arg = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $arg->type) {
                throw new \TypeError('SimpleXMLElement::getDocNamespaces(): Argument #2 ($fromRoot) must be of type bool');
            }
            $fromRoot = $arg->toBool();
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->array(VmSimpleXml::getDocNamespaces($entry, $recursive, $fromRoot));
        }
    }
}
