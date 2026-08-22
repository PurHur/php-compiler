<?php
// #33937 / re-#22710 / #30274 — foreign Element mutation must be Wrong Document Error (code 4).
$d1 = new DOMDocument();
$d1->loadXML('<r><a/></r>');
$d2 = new DOMDocument();
$d2->loadXML('<r><b/></r>');

foreach (['appendChild', 'insertBefore', 'replaceChild'] as $op) {
    try {
        if ('appendChild' === $op) {
            $d1->documentElement->appendChild($d2->documentElement->firstChild);
        } elseif ('insertBefore' === $op) {
            $d1->documentElement->insertBefore(
                $d2->documentElement->firstChild,
                $d1->documentElement->firstChild
            );
        } else {
            $d1->documentElement->replaceChild(
                $d2->documentElement->firstChild,
                $d1->documentElement->firstChild
            );
        }
        echo $op." NO_THROW\n";
    } catch (DOMException $e) {
        echo $op.' code='.$e->getCode().' msg='.$e->getMessage()."\n";
    }
}

// createElement foreign — ownerDocument slot path (#33937).
$d3 = new DOMDocument();
$d3->appendChild($d3->createElement('r'));
$d4 = new DOMDocument();
$foreign = $d4->createElement('x');
try {
    $d3->documentElement->appendChild($foreign);
    echo "createElement NO_THROW\n";
} catch (DOMException $e) {
    echo 'createElement code='.$e->getCode().' msg='.$e->getMessage()."\n";
}

// Same-document control — must still succeed.
$d5 = new DOMDocument();
$d5->loadXML('<r><a/><b/></r>');
$moved = $d5->documentElement->firstChild;
$d5->documentElement->appendChild($moved);
echo 'same-doc len='.$d5->documentElement->childNodes->length."\n";
