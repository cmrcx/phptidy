<?php
/**
 * function_return_reference.php
 *
 * @package default
 */


/**
 *
 */
function &f() {
	return $GLOBALS['z'];
}


$z = 1;
$y =& f();
