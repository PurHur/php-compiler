<?php
// #35383 — ClassConstFetch must seed Transliterator::* for thin AOT (peer #35366).
echo 'FORWARD=', Transliterator::FORWARD, "\n";
echo 'REVERSE=', Transliterator::REVERSE, "\n";
