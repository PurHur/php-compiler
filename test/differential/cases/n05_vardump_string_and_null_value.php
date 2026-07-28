<?php
// var_dump() of a string, and of a null held in a variable.
//
// Both were broken before #24220's var_dump half: the thin-AOT scalar bridge had arms for bool, int
// and float only, so string and null fell through to the "non-scalar value unsupported" abort — a
// string being reported as non-scalar is the tell.
//
// Deliberately does NOT use a literal var_dump(null): that crashes earlier, in call-site argument
// lowering, and is tracked by n02/n03. Keeping it out means this case guards the dispatch arms on
// their own rather than staying permanently red behind a different bug.
$s = 'hello world';
$empty = '';
$n = null;
var_dump($s);
var_dump($empty);
var_dump('a"b');
var_dump($n);
var_dump(strlen($s));
