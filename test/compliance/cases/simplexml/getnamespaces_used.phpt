--TEST--
SimpleXMLElement getNamespaces() reports used NS + inherited default (#22729, ext/simplexml/sxe.c)
--FILE--
<?php
$xml = '<root xmlns="urn:def" xmlns:a="urn:a"><child xmlns:b="urn:b"><b:x a:y="1"/></child></root>';
$x = new SimpleXMLElement($xml);

echo 'root_false=';
var_export($x->getNamespaces(false));
echo "\nroot_true=";
var_export($x->getNamespaces(true));
echo "\nchild_false=";
var_export($x->child->getNamespaces(false));
echo "\nunused=";
var_export((new SimpleXMLElement('<e xmlns:p="urn:p"/>'))->getNamespaces(false));
echo "\nattr_use=";
var_export((new SimpleXMLElement('<e xmlns:p="urn:p" p:x="1"/>'))->getNamespaces(false));
echo "\ndoc_root=";
var_export($x->getDocNamespaces(false));
echo "\n";
?>
--EXPECT--
root_false=array (
  '' => 'urn:def',
)
root_true=array (
  '' => 'urn:def',
  'b' => 'urn:b',
  'a' => 'urn:a',
)
child_false=array (
  '' => 'urn:def',
)
unused=array (
)
attr_use=array (
  'p' => 'urn:p',
)
doc_root=array (
  '' => 'urn:def',
  'a' => 'urn:a',
)
