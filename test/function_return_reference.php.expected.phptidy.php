<?php
/**
 * function_return_reference.php
 *
 * @package default
 * @see include.php
 */


/**
 *
 */
function &f() {
	return $GLOBALS['z'];
}


$z = 1;
$y =& f();
