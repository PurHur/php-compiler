<?php
// #27172 — AOT inet_pton/inet_ntop must match Zend (NestedJIT ?string was empty)
echo bin2hex(inet_pton('127.0.0.1')), "\n";
echo inet_ntop(hex2bin('7f000001')), "\n";
echo inet_ntop(inet_pton('::1')), "\n";
echo long2ip(ip2long('192.168.0.1')), "\n";
