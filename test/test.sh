#!/bin/bash
# run tests for phptidy
# Usage: ./test.sh

../phptidy.php suffix

passed=0
failed=0

for f in *.php
do

	if [[ ${f} == *.phptidy.php ]]
	then
		continue
	fi

	colordiff -u ${f}.expected.phptidy.php ${f}.phptidy.php \
	&& let passed=passed+1 \
	|| let failed=failed+1

done

echo "=> ${passed} tests passed, ${failed} tests failed."
