<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xml;

use PHPCompiler\Frame;
use PHPCompiler\ext\standard\VmStreamArg;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;

/**
 * xml_set_object() — bind handler method prefix object (php-src ext/xml/xml.c; #18203).
 *
 * Z_PARAM_OBJECT — null/non-object → TypeError; prior binding kept (#22798).
 * PROFILE≥8.4 stub #[\Deprecated] for Reflection (#28172, xml.stub.php).
 */
final class xml_set_object extends XmlFunction
{
    public function __construct()
    {
        parent::__construct('xml_set_object');
        $this->attributeEntries = XmlHandlerDeprecation::xmlSetObjectAttributeEntries();
        $this->deprecated = XmlHandlerDeprecation::xmlSetObjectDeprecatedMetadata();
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError('xml_set_object() expects exactly 2 arguments, '.$argc.' given');
        }
        $parser = XmlParserSupport::requireParser($frame->calledArgs[0], 'xml_set_object', 1);
        $objectArg = $frame->calledArgs[1]->resolveIndirect();
        // Z_PARAM_OBJECT before #[\Deprecated] body side-effects (xml.c / xml.stub.php; #22798).
        if (EnumCaseSupport::isEnumCaseVariable($objectArg) || Variable::TYPE_OBJECT !== $objectArg->type) {
            $given = EnumCaseSupport::isEnumCaseVariable($objectArg)
                ? EnumCaseSupport::typeNameForVariable($objectArg)
                : VmStreamArg::debugTypeName($objectArg);
            throw new \TypeError(\sprintf(
                'xml_set_object(): Argument #2 ($object) must be of type object, %s given',
                $given
            ));
        }
        // #[\Deprecated(since: '8.4', …)] — E_DEPRECATED on PROFILE≥8.4 (xml.stub.php; #21522).
        XmlHandlerDeprecation::emitXmlSetObject($frame);
        if (null === $frame->returnVar) {
            XmlParserHandlers::setObject($parser, $objectArg->toObject());

            return;
        }
        $frame->returnVar->bool(XmlParserHandlers::setObject($parser, $objectArg->toObject()));
    }
}
