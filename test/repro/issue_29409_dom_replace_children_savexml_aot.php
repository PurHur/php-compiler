<?php

declare(strict_types=1);

// AOT: replaceChildren must rewrite user-script INNER_XML for saveXML (#29409, re-#19507).
// Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_29409_dom_replace_children_savexml_aot.php
//      PHP_COMPILER_PROFILE=8.4 ./phpc build -o /tmp/rc29409.bin test/repro/issue_29409_dom_replace_children_savexml_aot.php && /tmp/rc29409.bin

$d = new DOMDocument();
$d->loadXML('<r><a/><b/></r>');
$d->documentElement->replaceChildren($d->createElement('z'));
echo $d->saveXML($d->documentElement), "\n";
$d->documentElement->replaceChildren();
echo $d->saveXML($d->documentElement), "\n";
$d->documentElement->replaceChildren($d->createElement('z'), 'txt');
echo $d->saveXML($d->documentElement), "\n";
