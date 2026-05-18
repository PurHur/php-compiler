--TEST--
Not-equal and not-identical operators (VM/JIT parity)
--FILE--
<?php
echo (null !== false) ? '1' : '0';
echo (0 !== '') ? '1' : '0';
echo (0 !== false) ? '1' : '0';
echo ('0' !== 0) ? '1' : '0';
echo (0 != false) ? '1' : '0';
echo (0 != '') ? '1' : '0';
echo (null != false) ? '1' : '0';
echo ('1' != 1) ? '1' : '0';
$method = 'POST';
echo ($method !== 'POST') ? '1' : '0';
echo ($method != 'GET') ? '1' : '0';
--EXPECT--
1111010001
