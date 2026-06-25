--TEST--
stdlib ini_set('session.save_path') — blocked after headers sent (#11548, ext/session/session.c)
--FILE--
<?php
echo "warmup\n";
$before = ini_get('session.save_path');
$old = ini_set('session.save_path', '/tmp/phpc-ini-session-blocked');
echo ini_get('session.save_path') === $before ? "unchanged\n" : "changed\n";
echo false === $old ? "set-false\n" : "set-old\n";
--EXPECTF--
warmup
Warning: Session ini settings cannot be changed after headers have already been sent in %s on line %d
unchanged
set-false
