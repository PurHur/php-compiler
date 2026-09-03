<?php
// @differential-skip-aot: AOT prints Error not DivisionByZeroError; no stdout fatal copy (#36383)
echo 1 % 0;
