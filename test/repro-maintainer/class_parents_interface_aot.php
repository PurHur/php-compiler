<?php
interface AotClassParentsIface5249 {}
interface AotClassParentsIfaceExt5249 extends AotClassParentsIface5249 {}
$p1 = class_parents('AotClassParentsIface5249');
$p2 = class_parents('AotClassParentsIfaceExt5249');
echo is_array($p1) && 0 === count($p1) ? '1' : '0';
echo is_array($p2) && 0 === count($p2) ? '1' : '0';
