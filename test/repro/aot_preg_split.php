<?php
/**
 * #27080 — AOT preg_split constexpr must materialize __value__* (not raw __value__**).
 */
echo json_encode(preg_split('/\s+/', 'a  b   c')), PHP_EOL;
