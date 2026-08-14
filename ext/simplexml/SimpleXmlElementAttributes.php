<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\Variable;

/** SimpleXMLElement::attributes — attribute accessor view (php-src ext/simplexml/sxe.c; #18038). */
final class SimpleXmlElementAttributes extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('attributes');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            throw new \LogicException('SimpleXMLElement::attributes() requires VM context');
        }
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('SimpleXMLElement::attributes() called without $this');
        }
        // php-src simplexml.stub.php: attributes(?string $namespaceOrPrefix = null, bool $isPrefix = false) (#30828).
        $this->requireAtMostUserArgCount($frame, 'SimpleXMLElement::attributes', 2);
        $entry = VmSimpleXml::requireElement(
            $frame->calledArgs[0]->resolveIndirect()->toObject(),
            'SimpleXMLElement::attributes()'
        );
        $namespaceOrPrefix = null;
        $isPrefix = true;
        if (\count($frame->calledArgs) >= 2) {
            $arg = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $arg->type) {
                if (Variable::TYPE_STRING !== $arg->type) {
                    throw new \TypeError('SimpleXMLElement::attributes(): Argument #1 ($namespaceOrPrefix) must be of type ?string');
                }
                $namespaceOrPrefix = $arg->toString();
            }
        }
        if (\count($frame->calledArgs) >= 3) {
            $arg = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $arg->type) {
                throw new \TypeError('SimpleXMLElement::attributes(): Argument #2 ($isPrefix) must be of type bool');
            }
            $isPrefix = $arg->toBool();
        }
        if (null !== $frame->returnVar) {
            $view = VmSimpleXml::attributes($frame->vmContext, $entry, $namespaceOrPrefix, $isPrefix);
            if (null === $view) {
                // Empty children()/named-child receiver — php-src returns null (#25148).
                $frame->returnVar->null();
            } else {
                $frame->returnVar->object($view);
            }
        }
    }
}
