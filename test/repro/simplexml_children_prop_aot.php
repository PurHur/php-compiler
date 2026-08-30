<?php
$x = new SimpleXMLElement('<r><c>1</c><b>2</b></r>');
echo 'plain='.(string) $x->c."\n";
echo 'view='.(string) $x->children()->c."\n";
$ch = $x->children();
echo 'local='.(string) $ch->c."\n";
echo 'dim0='.(string) $ch[0]."\n";
$y = new SimpleXMLElement('<r xmlns:a="urn:a"><a:c>1</a:c><b>2</b></r>');
echo 'ns='.(string) $y->children('urn:a')->c."\n";
