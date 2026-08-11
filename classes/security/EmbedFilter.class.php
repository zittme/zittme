<?php

/**
 * @deprecated
 */
class EmbedFilter
{
	/**
	 * Deprecated properties
	 * @var array
	 */
	public $whiteUrlList = array();
	public $whiteIframeUrlList = array();
	public $mimeTypeList = array();
	public $extList = array();

	/**
	 * Return EmbedFilter object
	 *
	 * @return EmbedFilter
	 */
	function getInstance()
	{
		return new self();
	}

	public function getWhiteUrlList()
	{
		return Zittme\Framework\Filters\MediaFilter::getObjectWhitelist();
	}

	public function getWhiteIframeUrlList()
	{
		return Zittme\Framework\Filters\MediaFilter::getIframeWhitelist();
	}

	function isWhiteDomain($urlAttribute)
	{
		return Zittme\Framework\Filters\MediaFilter::matchObjectWhitelist($urlAttribute);
	}

	function isWhiteIframeDomain($urlAttribute)
	{
		return Zittme\Framework\Filters\MediaFilter::matchIframeWhitelist($urlAttribute);
	}

	function isWhiteMimetype($mimeType)
	{
		return true;
	}

	function isWhiteExt($ext)
	{
		return true;
	}

	function check(&$content)
	{
		// This functionality has been moved to the HTMLFilter class.
	}

	function checkIframeTag(&$content)
	{
		// This functionality has been moved to the HTMLFilter class.
	}

	function checkObjectTag(&$content)
	{
		// This functionality has been moved to the HTMLFilter class.
	}

	function checkEmbedTag(&$content)
	{
		// This functionality has been moved to the HTMLFilter class.
	}

	function checkParamTag(&$content)
	{
		// This functionality has been moved to the HTMLFilter class.
	}
}
