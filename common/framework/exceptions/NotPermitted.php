<?php

namespace Zittme\Framework\Exceptions;

/**
 * The "not permitted" exception class.
 */
class NotPermitted extends \Zittme\Framework\Exception
{
	public function __construct($message = '', $code = 0, $previous = null)
	{
		if ($message === '')
		{
			$message = lang('msg_not_permitted');
		}
		parent::__construct($message, $code, $previous);
	}
}
