<?php
namespace App;
#[\Attribute]
class Marker {}
#[Marker]
const MARKED = 7;
echo MARKED, "\n";
$r = new \ReflectionConstant('App\\MARKED');
echo count($r->getAttributes()), "\n";
