<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlreader;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/**
 * XMLReader::readInnerXml() — serialize current node's children as XML (#19411).
 *
 * php-src zim_XMLReader_readInnerXml / xmlTextReaderReadInnerXml.
 */
final class XmlReaderReadInnerXml extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('readInnerXml');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \ArgumentCountError('XMLReader::readInnerXml() expects at least 1 argument, 0 given');
        }
        $entry = $frame->calledArgs[0]->resolveIndirect()->toObject();
        $xml = VmXmlReader::readInnerXml($entry);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($xml): void {
            $ret->string($xml);
        });
    }
}
