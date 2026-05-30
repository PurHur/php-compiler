--TEST--
Language: @ error-control operator suppresses notices/warnings (issue #3546)
--FILE--
<?php
echo @$undefined;
@trigger_error('x', E_USER_NOTICE);
echo "ok\n";
$bad = @file_get_contents('/no/such/path');
echo ($bad === false ? 'false' : 'not-false') . "\n";
--EXPECT--
ok
false
