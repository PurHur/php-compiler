<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlreader;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/**
 * XMLReader::readInnerXml() — serialize current node's children as XML (#19411).
 *
 * php-src zim_XMLReader_readInnerXml / xmlTextReaderReadInnerXml.
 */
final class XmlReaderReadInnerXml extends XmlReaderClassMethod
{
    public function __construct()
    {
        parent::__construct('readInnerXml');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactUserArgCount($frame, 'XMLReader::readInnerXml', 0);
        $entry = $frame->calledArgs[0]->resolveIndirect()->toObject();
        $xml = VmXmlReader::readInnerXml($entry);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($xml): void {
            $ret->string($xml);
        });
    }
}
