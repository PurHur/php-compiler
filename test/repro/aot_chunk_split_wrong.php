<?php
// Zend: "ab:cd:"  AOT historically: "abcd:cd:" (chunk boundary wrong)
echo chunk_split('abcd', 2, ':'), "\n";
