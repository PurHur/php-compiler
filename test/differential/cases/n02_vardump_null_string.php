<?php
// FAILS ON AOT — #24220. var_dump() of the other two scalars.
//
//   var_dump(null)   -> segfault, no output
//   var_dump('hi')   -> declines with the #23540 "non-scalar value unsupported" diagnostic, then
//                       core dumps. A string IS a scalar, so the diagnostic is also misclassifying.
//
// Bounding evidence: bool/int/float all print correctly (n01), and producing/testing a null is fine
// ($x === null and is_null($x) both pass 3/3). It is specifically PRINTING null or a string.
//
// The six existing #23540 cases (e01, e02, e03, e06, e13, e17) all pass arrays or objects, which is
// why the scalar half of var_dump was never covered.
var_dump('hi');
var_dump(null);
