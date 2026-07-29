--TEST--
bindec/hexdec/octdec/decbin/dechex/decoct Reflection + named args (JIT, issue #24788)
--FILE--
<?php
echo 'bindec=', bindec(binary_string: '1010'), "\n";
echo 'hexdec=', hexdec(hex_string: 'ff'), "\n";
echo 'octdec=', octdec(octal_string: '17'), "\n";
echo 'decbin=', decbin(num: 10), "\n";
echo 'dechex=', dechex(num: 255), "\n";
echo 'decoct=', decoct(num: 15), "\n";
--EXPECT--
bindec=10
hexdec=255
octdec=15
decbin=1010
dechex=ff
decoct=17
