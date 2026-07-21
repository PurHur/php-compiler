<?php
/** Repro #21667 — attributes()/children() property __get/isset/empty vs ArrayAccess. */
$sx = simplexml_load_string('<r a="1" b="2"/>');
$a = $sx->attributes();
echo 'attr_prop=', (string) $a->b;
echo ' attr_aa=', (string) $a['b'];
echo ' isset=', isset($a->a) ? 'Y' : 'N';
echo ' empty=', empty($a->a) ? 'Y' : 'N';
echo ' miss=', null === $a->missing ? 'null' : 'obj', "\n";

$sx2 = simplexml_load_string('<r><c>x</c><d>y</d></r>');
$ch = $sx2->children();
echo 'ch_prop=', (string) $ch->d;
echo ' ch_isset=', isset($ch->d) ? 'Y' : 'N', "\n";
