<?php
// @differential-skip-aot: var_dump() of string/bool/null needs Runtime->vm, absent in thin standalone AOT (#23540)
// #23540: var_dump aborted with rc=134 and empty stdout AND stderr in AOT builds.
// Scalars only: array output currently differs from Zend by one space of indent
// per nested line (#23726), so an array case would make this gate permanently red.
// Add one here once #23726 lands.
var_dump(7);
var_dump(1.5);
var_dump("s");
var_dump(true);
var_dump(null);
