<?php
/**
 * #23608 — procedural xmlwriter_* Reflection / named params match php-src stubs
 * (ext/xmlwriter/php_xmlwriter.stub.php): writer/value; reject legacy xmlwriter/content.
 */
$rf = new ReflectionFunction('xmlwriter_start_element');
echo 'start_element=', implode(',', array_map(static fn ($p) => $p->getName(), $rf->getParameters())), "\n";
$rf = new ReflectionFunction('xmlwriter_write_attribute');
echo 'write_attribute=', implode(',', array_map(static fn ($p) => $p->getName(), $rf->getParameters())), "\n";
$rf = new ReflectionFunction('xmlwriter_write_element');
echo 'write_element=', implode(',', array_map(static fn ($p) => $p->getName(), $rf->getParameters())), "\n";
$rf = new ReflectionFunction('xmlwriter_open_uri');
echo 'open_uri=', implode(',', array_map(static fn ($p) => $p->getName(), $rf->getParameters())), "\n";
$rf = new ReflectionFunction('xmlwriter_start_document');
echo 'start_document_req=', $rf->getNumberOfRequiredParameters(), "\n";
$rf = new ReflectionFunction('xmlwriter_start_dtd');
echo 'start_dtd=', implode(',', array_map(static fn ($p) => $p->getName(), $rf->getParameters())), "\n";

$w = xmlwriter_open_memory();
try {
    xmlwriter_start_element(writer: $w, name: 'r');
    echo "writer=OK\n";
} catch (Throwable $e) {
    echo 'writer=', $e->getMessage(), "\n";
}
try {
    xmlwriter_write_attribute(writer: $w, name: 'a', value: 'v');
    echo "value=OK\n";
} catch (Throwable $e) {
    echo 'value=', $e->getMessage(), "\n";
}
try {
    xmlwriter_start_element(xmlwriter: $w, name: 'x');
    echo "legacy-writer-ok\n";
} catch (Throwable $e) {
    echo "legacy-writer-reject\n";
}
try {
    xmlwriter_write_attribute(writer: $w, name: 'b', content: 'c');
    echo "legacy-content-ok\n";
} catch (Throwable $e) {
    echo "legacy-content-reject\n";
}
echo "ok\n";
