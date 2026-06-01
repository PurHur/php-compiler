--TEST--
stdlib password_get_info() — bcrypt hash metadata (issue #3649)
--FILE--
<?php
$hash = password_hash('secret', PASSWORD_DEFAULT);
$info = password_get_info($hash);
echo $info['algoName']."\n";
echo isset($info['options']['cost']) ? "cost_ok\n" : "cost_missing\n";
echo $info['options']['cost'] >= 4 ? "cost_range\n" : "cost_low\n";

$unknown = password_get_info('not-a-hash');
echo $unknown['algoName']."\n";
echo $unknown['algo'] === null ? "algo_null\n" : "algo_set\n";
--EXPECT--
bcrypt
cost_ok
cost_range
unknown
algo_null
