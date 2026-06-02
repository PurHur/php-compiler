<?php
var_dump(strspn('abc', 'a', 1, 1));
var_dump(strcspn('abc', 'a', 1, 1));
var_dump(strspn('abc123', 'abc', 2));
var_dump(strcspn('abc123', '123', 0, 3));
