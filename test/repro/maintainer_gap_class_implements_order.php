<?php
// #25315 — class_implements(ArrayObject) interface order
echo implode(',', class_implements(new ArrayObject())), "\n";
