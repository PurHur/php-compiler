<?php
// #26990 — ClassConstFetch as call arg after binary-op sibling must not steal plus result.
class Box {
    public const X = 10;
    public const Y = 20;
    public const Z = 30;
    public function get($n) { return $n; }
}
function take($n) { return $n; }
if (!class_exists('Box')) {
    echo "no\n";
    exit(1);
}
$b = new Box;
$method = $b->get(Box::X) . '-' . ($b->get(Box::Y) + 1) . '-' . $b->get(Box::Z);
$func = take(Box::X) . '-' . (take(Box::Y) + 1) . '-' . take(Box::Z);
echo $method, "\n", $func, "\n";
if ('10-21-30' !== $method || '10-21-30' !== $func) {
    fwrite(STDERR, "expected 10-21-30 got method=$method func=$func\n");
    exit(1);
}
