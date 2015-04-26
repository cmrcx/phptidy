<?php
/**
 * indent_encapsed.php
 *
 * @package default
 */


$query = "SELECT * FROM $table
	WHERE $column=".intval($value1)."
		AND id=".intval($value2);
