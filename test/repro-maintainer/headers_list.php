<?php

header('X-Test: one');
header('X-Test: two', false);
foreach (headers_list() as $line) {
    echo $line, "\n";
}
