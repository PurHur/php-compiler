<?php
// #35379 — ClassConstFetch must seed Collator::* for thin AOT (peer #35366).
echo 'PRIMARY=', Collator::PRIMARY, "\n";
echo 'SECONDARY=', Collator::SECONDARY, "\n";
echo 'SORT_REGULAR=', Collator::SORT_REGULAR, "\n";
