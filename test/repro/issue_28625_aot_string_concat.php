<?php
// #28625 root cause — AOT variable concat must not treat TYPE_VALUE strings as SimpleXMLElement
$k = 'a';
$s = $k . 'x';
echo "$s\n";
