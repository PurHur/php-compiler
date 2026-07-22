--TEST--
stdlib HashContext serialize/unserialize mid-update sha256 (#22331)
--FILE--
<?php
$c = hash_init('sha256');
hash_update($c, 'ab');
echo 'method=', method_exists($c, '__serialize') ? 'Y' : 'N', "\n";
$bag = $c->__serialize();
echo 'algo=', $bag[0], ' options=', $bag[1], ' magic=', $bag[3], "\n";
echo 'ctx_count=', count($bag[2]), "\n";
$s = serialize($c);
$c2 = unserialize($s);
hash_update($c2, 'cd');
$digest = hash_final($c2);
$expect = hash('sha256', 'abcd');
echo 'digest_ok=', $digest === $expect ? 'Y' : 'N', "\n";
echo $digest, "\n";
?>
--EXPECT--
method=Y
algo=sha256 options=0 magic=2
ctx_count=11
digest_ok=Y
88d4266fd4e6338d13b845fcf289579d209c897823b9217da3e161936f031589
