<?php
declare(strict_types=1);
/**
 * #35236 (re-#32831) — loop item($i) after replaceChild must walk siblings.
 */
$d = new DOMDocument();
$d->loadXML('<r><a/><b/><c/></r>');
$r = $d->documentElement;
$r->replaceChild($d->createElement('x'), $r->childNodes->item(1));
$list = $r->childNodes;
for ($i = 0; $i < $list->length; $i++) {
    echo $list->item($i)->nodeName;
}
echo "\n";
