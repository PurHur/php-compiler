<?php
// AOT lint-only: strcmp family Zend stub named params (#23335, ext/standard/string.stub.php)
// Named-arg dispatch only — ReflectionFunction::getParameters is a separate AOT case-fold gap.
echo strcmp(string1: 'a', string2: 'b'), "\n";
echo strcasecmp(string1: 'A', string2: 'a'), "\n";
echo strncmp(string1: 'abc', string2: 'abd', length: 2), "\n";
echo strncasecmp(string1: 'AbC', string2: 'abd', length: 2), "\n";
