<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\Variable;

/** SimpleXMLElement::children — direct element child view (php-src ext/simplexml/sxe.c; #18038). */
final class SimpleXmlElementChildren extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('children');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            throw new \LogicException('SimpleXMLElement::children() requires VM context');
        }
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('SimpleXMLElement::children() called without $this');
        }
        // php-src simplexml.stub.php: children(?string $namespaceOrPrefix = null, bool $isPrefix = false) (#30828).
        $this->requireAtMostUserArgCount($frame, 'SimpleXMLElement::children', 2);
        $entry = VmSimpleXml::requireElement(
            $frame->calledArgs[0]->resolveIndirect()->toObject(),
            'SimpleXMLElement::children()'
        );
        $namespaceOrPrefix = null;
        $isPrefix = true;
        if (\count($frame->calledArgs) >= 2) {
            $arg = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $arg->type) {
                if (Variable::TYPE_STRING !== $arg->type) {
                    throw new \TypeError('SimpleXMLElement::children(): Argument #1 ($namespaceOrPrefix) must be of type ?string');
                }
                $namespaceOrPrefix = $arg->toString();
            }
        }
        if (\count($frame->calledArgs) >= 3) {
            $arg = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $arg->type) {
                throw new \TypeError('SimpleXMLElement::children(): Argument #2 ($isPrefix) must be of type bool');
            }
            $isPrefix = $arg->toBool();
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->object(VmSimpleXml::children($frame->vmContext, $entry, $namespaceOrPrefix, $isPrefix));
        }
    }
}
