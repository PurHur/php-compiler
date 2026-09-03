<?php
// @differential-skip-aot: AOT compile fails on fwrite(STDOUT) (#36383)
fwrite(STDOUT, "out\n");
