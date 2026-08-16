<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;

/**
 * SimpleXMLElement::__construct — parse data and attach live node state
 * (php-src ext/simplexml/sxe.c; required by #19307 / #19306 / #31514).
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
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('SimpleXMLElement::__construct() called without $this');
        }
        // php-src simplexml.stub.php: __construct(string $data, int $options = 0, bool $dataIsURL = false, string $namespaceOrPrefix = "", bool $isPrefix = false) (#30828).
        $this->requireUserArgCountRange($frame, 'SimpleXMLElement::__construct', 1, 5);
        $entry = $frame->calledArgs[0]->resolveIndirect()->toObject();
        // Z_PARAM_STR $data — soft-null DEP+coerce then parse (php-src sxe.c / #31514; same
        // polarity as simplexml_load_string #21502). Empty/invalid XML → Exception.
        $data = VmString::stringBuiltinArgForFrame(
            $frame,
            1,
            'SimpleXMLElement::__construct',
            0,
            'data',
            false
        );
        VmSimpleXml::constructFromData(
            $frame->vmContext,
            $entry,
            $data,
            $frame
        );
    }
}
