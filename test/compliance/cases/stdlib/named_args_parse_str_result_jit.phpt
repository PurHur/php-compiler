--TEST--
parse_str() named string:/result: out-parameter (JIT, issue #16627)
--FILE--
<?php
declare(strict_types=1);
parse_str(string: 'a=1&b=2', result: $out);
echo $out['a'], ',', $out['b'], "\n";
--EXPECT--
1,2
