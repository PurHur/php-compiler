<?php
// Compile-only (#4331): ord('') must compile and return 0 at runtime.
echo ord(''), "\n";
