--TEST--
Language: nested DOMNode::cloneNode(true/false) on parentNode MethodCall (#25876)
--FILE--
<?php
declare(strict_types=1);
if (!class_exists('DOMDocument', false)) {
    print "skip: DOMDocument not available\n";
    exit(0);
}
$d = new DOMDocument();
$d->loadXML('<r><a>1</a><b>2</b></r>');
$a = $d->getElementsByTagName('a')->item(0);
$b = $d->getElementsByTagName('b')->item(0);
$a->parentNode->replaceChild($b->cloneNode(true), $a);
echo 'replace_true=', $d->C14N(), "\n";

$d = new DOMDocument();
$d->loadXML('<r><a>1</a><b><c>x</c></b></r>');
$a = $d->getElementsByTagName('a')->item(0);
$b = $d->getElementsByTagName('b')->item(0);
$a->parentNode->replaceChild($b->cloneNode(false), $a);
echo 'replace_false=', $d->C14N(), "\n";

$d = new DOMDocument();
$d->loadXML('<r><a>1</a><b>2</b></r>');
$a = $d->getElementsByTagName('a')->item(0);
$b = $d->getElementsByTagName('b')->item(0);
$a->parentNode->insertBefore($b->cloneNode(true), $a);
echo 'insert_true=', $d->C14N(), "\n";

$d = new DOMDocument();
$d->loadXML('<r><a>1</a><b>2</b></r>');
$a = $d->getElementsByTagName('a')->item(0);
$b = $d->getElementsByTagName('b')->item(0);
$a->parentNode->appendChild($b->cloneNode(true));
echo 'append_true=', $d->C14N(), "\n";
?>
--EXPECT--
replace_true=<r><b>2</b><b>2</b></r>
replace_false=<r><b></b><b><c>x</c></b></r>
insert_true=<r><b>2</b><a>1</a><b>2</b></r>
append_true=<r><a>1</a><b>2</b><b>2</b></r>
