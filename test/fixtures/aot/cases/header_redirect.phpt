--TEST--
AOT: header() Location redirect emits Status and Location (issue #122)
--FILE--
<?php
header('Location: /thanks', true, 302);
--EXPECT--
Status: 302
Location: /thanks
