--TEST--
XMLReader methods excess argc → ArgumentCountError (#30641)
--RUNFILE--
../../../repro/maintainer_gap_xmlreader_excess_argc_30641.php
--EXPECT--
XMLReader::open() expects at most 3 arguments, 4 given
XMLReader::XML() expects at most 3 arguments, 4 given
XMLReader::close() expects exactly 0 arguments, 1 given
XMLReader::read() expects exactly 0 arguments, 1 given
XMLReader::next() expects at most 1 argument, 2 given
XMLReader::expand() expects at most 1 argument, 2 given
XMLReader::getAttribute() expects exactly 1 argument, 2 given
XMLReader::getAttributeNo() expects exactly 1 argument, 2 given
XMLReader::getAttributeNs() expects exactly 2 arguments, 3 given
XMLReader::isValid() expects exactly 0 arguments, 1 given
XMLReader::readInnerXml() expects exactly 0 arguments, 1 given
XMLReader::readOuterXml() expects exactly 0 arguments, 1 given
XMLReader::readString() expects exactly 0 arguments, 1 given
XMLReader::moveToAttribute() expects exactly 1 argument, 2 given
XMLReader::moveToAttributeNo() expects exactly 1 argument, 2 given
XMLReader::moveToAttributeNs() expects exactly 2 arguments, 3 given
XMLReader::moveToFirstAttribute() expects exactly 0 arguments, 1 given
XMLReader::moveToNextAttribute() expects exactly 0 arguments, 1 given
XMLReader::moveToElement() expects exactly 0 arguments, 1 given
XMLReader::lookupNamespace() expects exactly 1 argument, 2 given
XMLReader::setParserProperty() expects exactly 2 arguments, 3 given
XMLReader::getParserProperty() expects exactly 1 argument, 2 given
XMLReader::setSchema() expects exactly 1 argument, 2 given
XMLReader::setRelaxNGSchema() expects exactly 1 argument, 2 given
XMLReader::setRelaxNGSchemaSource() expects exactly 1 argument, 2 given
LEGAL_OK
