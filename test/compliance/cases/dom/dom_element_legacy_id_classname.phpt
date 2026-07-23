--TEST--
stdlib legacy DOMElement::$id / $className virtual props — PHP 8.3+ (#22457, ext/dom/php_dom.stub.php)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$d = new DOMDocument();
$d->loadHTML('<html><body><a id="i" class="x y">t</a><b>no</b></body></html>', LIBXML_NOERROR);
$a = $d->getElementsByTagName('a')->item(0);
$b = $d->getElementsByTagName('b')->item(0);
echo 'isset_id=', (int) isset($a->id), ' isset_cn=', (int) isset($a->className), "\n";
echo 'id=', $a->id, "\n";
echo 'className=', $a->className, "\n";
$a->id = 'j';
$a->className = 'p q';
echo 'id_attr=', $a->getAttribute('id'), "\n";
echo 'class_attr=', $a->getAttribute('class'), "\n";
echo 'id2=', $a->id, ' className2=', $a->className, "\n";
echo 'b_id=', var_export($b->id, true), ' b_cn=', var_export($b->className, true), "\n";
echo 'empty_b_id=', (int) empty($b->id), "\n";
try {
    $a->id = 1;
    echo "assign_int=ok\n";
} catch (TypeError $e) {
    echo 'assign_int=', $e->getMessage(), "\n";
}
?>
--EXPECT--
isset_id=1 isset_cn=1
id=i
className=x y
id_attr=j
class_attr=p q
id2=j className2=p q
b_id='' b_cn=''
empty_b_id=1
assign_int=Cannot assign int to property DOMElement::$id of type string
