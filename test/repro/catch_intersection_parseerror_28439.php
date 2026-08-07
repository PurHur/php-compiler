<?php
// Issue #28439 — catch (A&B) / catch ((A&B)) must ParseError like Zend (catch_name_list is | only)
interface A {}
interface B {}
class E extends Exception implements A, B {}
try { throw new E("x"); }
catch (A&B $e) { echo "caught\n"; }
