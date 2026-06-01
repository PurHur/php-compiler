--TEST--
Language: @ error-control JIT/AOT — nested @ and suppressed array read (issue #4070)
--FILE--
<?php
$a = [];
@$x = $a['missing'];
@(@trigger_error('nested', E_USER_NOTICE));
echo "ok\n";
$bad = @file_get_contents('/no/such/path');
echo ($bad === false ? 'false' : 'not-false') . "\n";
--EXPECT--
ok
false
