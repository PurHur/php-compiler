<?php
// AOT lint-only: base64_encode/urlencode/urldecode/rawurl* Zend stub named params (#23257)
echo base64_encode(string: 'ab'), "\n";
echo urlencode(string: 'a b'), "\n";
echo urldecode(string: 'a+b'), "\n";
echo rawurlencode(string: 'a b'), "\n";
echo rawurldecode(string: 'a%20b'), "\n";
