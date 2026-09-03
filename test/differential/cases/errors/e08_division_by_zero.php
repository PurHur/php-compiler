<?php
// @differential-skip-aot: AOT compile fails on integer / 0 (#36383)
echo 1 / 0;
