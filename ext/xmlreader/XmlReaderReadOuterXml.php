<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlreader;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/**
 * XMLReader::readOuterXml() — serialize current node (+ descendants) as XML (#19411).
 *
 * php-src zim_XMLReader_readOuterXml / xmlTextReaderReadOuterXml.
 */
final class XmlReaderReadOuterXml extends XmlReaderClassMethod
{
    public function __construct()
    {
        parent::__construct('readOuterXml');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactUserArgCount($frame, 'XMLReader::readOuterXml', 0);
        $entry = $frame->calledArgs[0]->resolveIndirect()->toObject();
        $xml = VmXmlReader::readOuterXml($entry);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($xml): void {
            $ret->string($xml);
        });
    }
}
