<?php
enum Status: int { case Active = 1; case Archived = 2; }
$case = (new ReflectionEnum(Status::class))->getCases()[0];
var_dump($case::class);
var_dump($case->getBackingValue());
var_dump($case->isBacked());
