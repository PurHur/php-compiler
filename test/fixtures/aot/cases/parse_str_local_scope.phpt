--TEST--
AOT: parse_str() without $result binds locals (issue #3708)
--FILE--
<?php
function bind(): void {
    parse_str('id=42&name=Ada');
    echo $id, ':', $name, "\n";
}
bind();
--EXPECT--
42:Ada
