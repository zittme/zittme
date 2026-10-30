<?php

namespace Zittme\Framework;

/**
 * The IndexNow class.
 *
 * Notifies participating search engines that a URL has changed.
 * Only the address and the time are sent. The content is not.
 */
class IndexNow
{
	/**
	 * The shared endpoint. Participating search engines relay to one another.
	 */
	protected const ENDPOINT = 'https://api.indexnow.org/indexnow';

	/**
	 * URLs collected during this request.
	 */
	protected static $_pending = array();

	/**
	 * Whether the shutdown function has been registered.
	 */
	protected static $_registered = false;

	/**
	 * Check whether IndexNow is turned on.
	 *
	 * @return bool
	 */
	public static function isEnabled(): bool
	{
		return (bool)Config::get('seo.indexnow');
	}

	/**
	 * Return the authentication key, creating it if necessary.
	 *
	 * @return string
	 */
	public static function getKey(): string
	{
		$key = (string)Config::get('seo.indexnow_key');
		if (!preg_match('/^[0-9a-f]{32}$/', $key))
		{
			$key = bin2hex(random_bytes(16));
			Config::set('seo.indexnow_key', $key);
			Config::save();
		}
		return $key;
	}

	/**
	 * Return the path of the key file inside the web root.
	 *
	 * @return string
	 */
	public static function getKeyFilePath(): string
	{
		return \RX_BASEDIR . self::getKey() . '.txt';
	}

	/**
	 * Write the key file that search engines read to verify ownership.
	 *
	 * @return bool
	 */
	public static function writeKeyFile(): bool
	{
		$path = self::getKeyFilePath();
		if (file_exists($path))
		{
			return true;
		}
		return \FileHandler::writeFile($path, self::getKey()) !== false;
	}

	/**
	 * Queue a URL to be submitted at the end of the request.
	 *
	 * @param string $url
	 * @return void
	 */
	public static function add(string $url): void
	{
		if (!self::isEnabled() || $url === '' || !URL::isInternalURL($url))
		{
			return;
		}

		self::$_pending[$url] = true;

		if (!self::$_registered)
		{
			self::$_registered = true;
			register_shutdown_function(function() {
				try
				{
					if (function_exists('fastcgi_finish_request'))
					{
						@fastcgi_finish_request();
					}
					self::flush();
				}
				catch (\Throwable $e)
				{
				}
			});
		}
	}

	/**
	 * Queue the permanent URL of a document.
	 *
	 * @param object $obj
	 * @return void
	 */
	public static function addDocument($obj): void
	{
		if (!self::isEnabled() || empty($obj->document_srl))
		{
			return;
		}
		if (isset($obj->status) && $obj->status !== 'PUBLIC')
		{
			return;
		}

		try
		{
			$oDocument = \DocumentModel::getDocument($obj->document_srl, false, false);
			if ($oDocument && $oDocument->isExists())
			{
				$module_info = \ModuleModel::getModuleInfoByModuleSrl($oDocument->get('module_srl'));
				if ($module_info && class_exists('Zittme\Modules\Menu\Models\Exposure') && (\Zittme\Modules\Menu\Models\Exposure::isNoindex($module_info) || \Zittme\Modules\Menu\Models\Exposure::isOutOfSitemap($module_info)))
				{
					return;
				}
				if ($oDocument->isSecret() || $oDocument->getStatus() !== 'PUBLIC')
				{
					return;
				}
				self::add($oDocument->getPermanentUrl());
			}
		}
		catch (\Throwable $e)
		{
		}
	}

	/**
	 * Submit the collected URLs.
	 *
	 * @return bool
	 */
	public static function flush(): bool
	{
		$urls = array_keys(self::$_pending);
		self::$_pending = array();

		if (!count($urls) || !self::isEnabled())
		{
			return false;
		}

		$key = self::getKey();
		self::writeKeyFile();

		$host = URL::getCurrentDomain();
		$payload = array(
			'host' => $host,
			'key' => $key,
			'keyLocation' => URL::getCurrentDomainURL('/') . $key . '.txt',
			'urlList' => array_slice($urls, 0, 10000),
		);

		try
		{
			HTTP::post(self::ENDPOINT, json_encode($payload), ['Content-Type' => 'application/json; charset=utf-8'], [], ['timeout' => 5]);
			return true;
		}
		catch (\Throwable $e)
		{
			return false;
		}
	}
}
