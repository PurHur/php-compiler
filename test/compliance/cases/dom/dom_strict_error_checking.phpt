--TEST--
DOMDocument::$strictErrorChecking=false → warn+false on create*; setAttribute always throws (#24804)
--FILE--
<?php
function expect_false(string $label, $r): void
{
    echo $label, '=', ($r === false ? 'false' : 'NOT_FALSE'), "\n";
}

function expect_ex(string $label, int $code, callable $fn): void
{
    try {
        $fn();
        echo $label, "=NO_THROW\n";
    } catch (DOMException $e) {
        echo $label, '=', ($e->getCode() === $code ? '1' : '0'), "\n";
    }
}

$d = new DOMDocument();
$d->strictErrorChecking = false;
expect_false('ce', @$d->createElement('123bad'));
expect_false('ca', @$d->createAttribute('123bad'));
expect_false('cens', @$d->createElementNS('urn:x', '1:x'));

$d->loadXML('<r/>');
$el = $d->documentElement;
expect_ex('sa', 5, fn () => $el->setAttribute('123bad', 'v'));
@$el->setAttributeNS('urn:x', '1:x', 'v');
echo 'sans=', var_export($el->getAttributeNS('urn:x', 'x'), true), "\n";

$strict = new DOMDocument();
expect_ex('ce_strict', 5, fn () => $strict->createElement('123bad'));
echo $strict->createElement('ok')->tagName, "\n";
--EXPECT--
ce=false
ca=false
cens=false
sa=1
sans=''
ce_strict=1
ok
