<?php
declare(strict_types=1);
/**
 * #20642 — AOT DOMNode::normalize() dispatch (re-#15484).
 * Merge observation uses VM compliance; AOT asserts the method is callable
 * (createTextNode nested-JIT for live text nodes is a follow-up).
 */
$d = new DOMDocument();
$r = $d->createElement('r');
$d->appendChild($r);
$r->append($d->createElement('c'));
$r->normalize();
echo "ok\n";
