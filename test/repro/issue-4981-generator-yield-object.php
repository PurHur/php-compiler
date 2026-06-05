<?php
function gen() {
    yield ['k' => 1];
    yield new stdClass;
}
foreach (gen() as $v) {
    echo is_array($v) ? 'arr' : get_class($v), "\n";
}
