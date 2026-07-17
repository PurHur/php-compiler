<?php
// AOT repro: //namespace::* lengths (#20206, re-#20170).
declare(strict_types=1);
$d = new DOMDocument();
$d->loadXML('<r xmlns:a="http://a"><c xmlns:b="http://b"/></r>');
$xp = new DOMXPath($d);
echo 'desc_len=', $xp->query('//namespace::*')->length, "\n";
echo 'c_len=', $xp->query('//c/namespace::*')->length, "\n";
echo 'r_a_len=', $xp->query('/r/namespace::a')->length, "\n";
