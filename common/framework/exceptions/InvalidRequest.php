<?php

namespace Zittme\Framework\Exceptions;

/**
 * The "invalid request" exception class.
 */
class InvalidRequest extends \Zittme\Framework\Exception
{
	public function __construct($message = '', $code = 0, $previous = null)
	{
		if ($message === '')
		{
			$message = lang('msg_invalid_request');
		}
		parent::__construct($message, $code, $previous);
	}
}
