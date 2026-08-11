<?php

namespace Zittme\Framework\Exceptions;

/**
 * The "target not found" exception class.
 */
class TargetNotFound extends \Zittme\Framework\Exception
{
	public function __construct($message = '', $code = 0, $previous = null)
	{
		if ($message === '')
		{
			$message = lang('msg_not_founded');
		}
		parent::__construct($message, $code, $previous);
	}
}
