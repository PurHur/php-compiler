--TEST--
SimpleXML: simplexml_import_dom() TypeError + SimpleXMLElement wrap + null on bad nodetype (#20291, ext/simplexml/simplexml.c)
--FILE--
<?php
try {
    simplexml_import_dom(new stdClass());
    echo "stdClass uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    simplexml_import_dom(1);
    echo "int uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

$d = new DOMDocument();
$d->loadXML('<r><e>t</e><!--c--></r>');
try {
    simplexml_import_dom($d->getElementsByTagName('*'));
    echo "nodelist uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

$fromDom = simplexml_import_dom($d);
echo 'fromDom=', (string) $fromDom->e, "\n";

$sxe = simplexml_load_string('<a>hi</a>');
$fromSxe = simplexml_import_dom($sxe);
echo 'fromSxe=', (string) $fromSxe, ' same=', ($fromSxe === $sxe ? '1' : '0'), "\n";

$comment = $d->documentElement->lastChild;
$bad = simplexml_import_dom($comment);
echo 'comment=', var_export($bad, true), "\n";
?>
--EXPECTF--
PHP Warning:  simplexml_import_dom(): Invalid Nodetype to import in %s on line %d
simplexml_import_dom(): Argument #1 ($node) must be of type SimpleXMLElement|DOMNode, stdClass given
simplexml_import_dom(): Argument #1 ($node) must be of type object, int given
simplexml_import_dom(): Argument #1 ($node) must be of type SimpleXMLElement|DOMNode, DOMNodeList given
fromDom=t
fromSxe=hi same=0
comment=NULL
