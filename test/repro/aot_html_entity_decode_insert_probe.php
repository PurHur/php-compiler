<?php
// #35069 — string-local html_entity_decode must AOT-compile (insert block restore).
$e = '&eacute;';
echo html_entity_decode($e, ENT_QUOTES), "\n";
$a = '&lt;a&gt;&amp;b';
echo html_entity_decode($a, ENT_QUOTES), "\n";
