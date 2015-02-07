<?php
/**
 * indent_operators.php
 *
 * @package default
 */


$i = 1 +
	2 +
	3;

$i = isset($x) and
	isset($y) and
	isset($z);

$i = isset($x) ||
	isset($y) ||
	isset($z);

$i = isset($x) &&
	isset($y) &&
	isset($z);

$i +=
	$y +
	$z;

$i = $a
	? $b
	: $c;

$i .=
	$a &
	$b;
