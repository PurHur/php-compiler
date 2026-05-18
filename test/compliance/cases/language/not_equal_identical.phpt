--TEST--
Not-equal and not-identical operators (VM/JIT parity)
--FILE--
<?php
function b($v): string {
    return $v ? '1' : '0';
}

// Strict inequality edge cases
echo b(null !== false);
echo b(0 !== '');
echo b(0 !== false);
echo b('0' !== 0);

// Loose inequality
echo b(0 != false);
echo b(0 != '');
echo b(null != false);
echo b('1' != 1);

// Web-style guard
$method = 'POST';
echo b($method !== 'POST');
echo b($method != 'GET');
--EXPECT--
1111000001
