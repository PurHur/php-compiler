<?php

$hash = password_hash('secret', PASSWORD_BCRYPT);
echo password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12]) ? "cost_higher\n" : "cost_higher_no\n";
echo password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 10]) ? "cost_match\n" : "cost_match_no\n";
echo password_needs_rehash($hash, PASSWORD_DEFAULT) ? "default_needs\n" : "default_ok\n";
