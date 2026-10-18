<?php

namespace PSP\Db;

abstract class CustomGenerator
{
	/**
	 * @param int $qty Number of records to generate
	 * @return mixed[]
	 */
	abstract public function generate(int $qty): array;

}
