<?php
// Issue #27066: AOT array_unique() must compile and match Zend on scalars.
echo implode(',', array_unique([1, 2, 2, 3])), PHP_EOL;
echo implode(',', array_unique(['a', 'A', 'a'])), PHP_EOL;
