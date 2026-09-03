<?php
// @differential-skip-aot: AOT compile fails on fwrite(STDERR) (#36383)
fwrite(STDERR, "errline\n");
exit(1);
