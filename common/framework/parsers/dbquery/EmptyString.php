<?php

namespace Zittme\Framework\Parsers\DBQuery;

/**
 * Empty string class.
 */
class EmptyString
{
	public function __toString(): string
	{
		return "''";
	}
}
