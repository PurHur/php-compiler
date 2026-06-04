--TEST--
Language: throw from finally chains pending try exception on uncaught fatal (#5867)
--FILE--
<?php
try {
    throw new Exception('inner');
} finally {
    throw new Exception('finally');
}
--EXPECT_EXIT--
255
