<?php
declare(strict_types=1);

// Compile-only (#28931 / re-#7230): RequestMethod phantom must stay absent through AOT.
echo enum_exists('RequestMethod', false) ? "yes\n" : "no\n";
