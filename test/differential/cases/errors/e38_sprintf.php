<?php
// @differential-skip-aot: AOT compile hangs/times out on sprintf (#36383)
echo sprintf('%d', 9), "\n";
