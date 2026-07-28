<?php
// #24115 — AOT preg_replace (was empty string)
echo preg_replace('/\s+/', '_', 'a  b'), "\n";
