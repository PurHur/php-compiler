<?php
// #35384 — ClassConstFetch must seed Transliterator::* for thin AOT (peer #35379).
echo 'FORWARD=', Transliterator::FORWARD, "\n";
echo 'REVERSE=', Transliterator::REVERSE, "\n";
