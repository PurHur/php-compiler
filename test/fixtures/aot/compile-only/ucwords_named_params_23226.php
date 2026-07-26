<?php
// AOT lint-only: ucwords Zend stub named params (#23226)
// Runtime AOT for ucwords currently segfaults (pre-existing; see cases/ucwords.phpt).
echo ucwords(string: 'hello world'), "\n";
echo ucwords(string: 'a-b', separators: '-'), "\n";
