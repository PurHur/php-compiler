--TEST--
stdlib password_needs_rehash() — bcrypt cost and invalid hash (issue #3279)
--FILE--
<?php
$hash = password_hash('secret', PASSWORD_BCRYPT);
echo password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12]) ? "cost_higher\n" : "cost_higher_no\n";
echo password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 10]) ? "cost_match\n" : "cost_match_no\n";
echo password_needs_rehash($hash, PASSWORD_BCRYPT) ? "no_opts\n" : "no_opts_no\n";
echo password_needs_rehash('not-a-hash', PASSWORD_BCRYPT, []) ? "invalid_yes\n" : "invalid_no\n";
echo password_needs_rehash($hash, 999, []) ? "bad_algo\n" : "bad_algo_no\n";
--EXPECT--
cost_higher
cost_match_no
no_opts_no
invalid_yes
bad_algo_no
