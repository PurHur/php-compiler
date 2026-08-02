<?php
/**
 * #27080 — AOT json_encode(preg_split(lit, lit)) must compile and print Zend output.
 */
echo json_encode(preg_split('/\s+/', 'a  b   c')), PHP_EOL;
