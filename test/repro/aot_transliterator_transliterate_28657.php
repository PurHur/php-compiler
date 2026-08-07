<?php
$t = Transliterator::create('Any-Latin; Latin-ASCII');
echo $t->transliterate('café'), "\n";
