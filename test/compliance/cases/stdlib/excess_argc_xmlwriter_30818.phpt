--TEST--
XMLWriter methods excess argc → ArgumentCountError (#30818)
--RUNFILE--
../../../repro/maintainer_gap_xmlwriter_excess_argc_30818.php
--EXPECT--
XMLWriter::openMemory() expects exactly 0 arguments, 1 given
XMLWriter::startDocument() expects at most 3 arguments, 4 given
XMLWriter::startElement() expects exactly 1 argument, 2 given
XMLWriter::text() expects exactly 1 argument, 2 given
XMLWriter::endElement() expects exactly 0 arguments, 1 given
XMLWriter::outputMemory() expects at most 1 argument, 2 given
XMLWriter::flush() expects at most 1 argument, 2 given
XMLWriter::setIndent() expects exactly 1 argument, 2 given
XMLWriter::writeAttribute() expects exactly 2 arguments, 3 given
XMLWriter::startElementNs() expects exactly 3 arguments, 4 given
XMLWriter::writeElement() expects at most 2 arguments, 3 given
XMLWriter::startCdata() expects exactly 0 arguments, 1 given
XMLWriter::endDocument() expects exactly 0 arguments, 1 given
XMLWriter::openUri() expects exactly 1 argument, 2 given
XMLWriter::startDtd() expects at most 3 arguments, 4 given
XMLWriter::writeDtdEntity() expects at most 6 arguments, 7 given
<?xml version="1.0" encoding="UTF-8"?>
<root>hi</root>
