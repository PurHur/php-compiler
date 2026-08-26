<?php
// #35225 blocker path — runtime body, literal charset (#34299)
function s(string $x): string { return $x; }
echo mb_encode_mimeheader(s('café'), 'UTF-8', 'B'), "\n";
