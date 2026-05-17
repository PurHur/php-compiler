--TEST--
Integration: decbin, bindec, decoct, octdec round-trips
--FILE--
<?php
echo decbin(bindec('1010')), "\n";
echo decoct(octdec('77')), "\n";
echo bindec(decbin(255)), "\n";
echo octdec(decoct(512)), "\n";
echo strlen(decbin(10)), "\n";
--EXPECT--
1010
77
255
512
4
