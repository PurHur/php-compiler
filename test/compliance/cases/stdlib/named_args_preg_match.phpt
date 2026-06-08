--TEST--
preg_match() named flags argument (VM, issue #6747)
--FILE--
<?php
var_dump(preg_match(subject: 'a', pattern: '/a/'));
--EXPECT--
int(1)
