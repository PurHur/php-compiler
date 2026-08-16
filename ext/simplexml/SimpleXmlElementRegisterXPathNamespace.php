<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;

/** SimpleXMLElement::registerXPathNamespace — xpath prefix binding (php-src ext/simplexml/sxe.c; #18038 / #31656). */
final class SimpleXmlElementRegisterXPathNamespace extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('registerXPathNamespace');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('SimpleXMLElement::registerXPathNamespace() called without $this');
        }
        // php-src simplexml.stub.php: registerXPathNamespace(string $prefix, string $namespace) (#30828).
        $this->requireExactUserArgCount($frame, 'SimpleXMLElement::registerXPathNamespace', 2);
        $entry = VmSimpleXml::requireElement(
            $frame->calledArgs[0]->resolveIndirect()->toObject(),
            'SimpleXMLElement::registerXPathNamespace()'
        );
        // Z_PARAM_STR — soft-null DEP+coerce; empty prefix → false (php-src sxe.c / #31656).
        $prefix = VmString::stringBuiltinArgForFrame(
            $frame,
            1,
            'SimpleXMLElement::registerXPathNamespace',
            0,
            'prefix',
            false
        );
        $namespace = VmString::stringBuiltinArgForFrame(
            $frame,
            2,
            'SimpleXMLElement::registerXPathNamespace',
            1,
            'namespace',
            false
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmSimpleXml::registerXPathNamespace(
                $entry,
                $prefix,
                $namespace
            ));
        }
    }
}
