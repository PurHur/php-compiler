<?php
/**
 * #24886 AOT — str_replace runtime unchanged (by-ref $count update is pre-existing AOT gap on master).
 */
echo str_replace('o', '0', 'hello world'), "\n";
