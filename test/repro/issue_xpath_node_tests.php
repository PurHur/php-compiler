<?php
/**
 * Repro #20456 — XPath node tests + multi-segment // paths.
 * Zend: comment=1 text=3 pi=1 node=9 wrap_a=1 a_text=2 r_node=5
 */
$d = new DOMDocument();
$d->loadXML('<r><![CDATA[x]]><?pi y?><!--c--><a>t</a><wrap><a>1</a></wrap></r>');
$xp = new DOMXPath($d);

echo 'comment=', $xp->query('//comment()')->length, "\n";
echo 'text=', $xp->query('//text()')->length, "\n";
echo 'pi=', $xp->query('//processing-instruction()')->length, "\n";
echo 'pi_named=', $xp->query('//processing-instruction("pi")')->length, "\n";
echo 'node=', $xp->query('//node()')->length, "\n";
echo 'wrap_a=', $xp->query('//wrap/a')->length, "\n";
echo 'a_text=', $xp->query('//a/text()')->length, "\n";
echo 'r_node=', $xp->query('/r/node()')->length, "\n";
echo 'rel_comment=', $xp->query('.//comment()', $d->documentElement)->length, "\n";
