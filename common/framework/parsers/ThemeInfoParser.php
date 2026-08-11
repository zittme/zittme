<?php

namespace Zittme\Framework\Parsers;

/**
 * Theme info (theme.xml) parser.
 *
 * @see docs/THEME-PACKAGE.md
 */
class ThemeInfoParser extends BaseParser
{
	/**
	 * Highest schema version this engine understands.
	 * Themes declaring a higher schema are rejected at install time.
	 */
	public const MAX_SCHEMA = '1.0';

	/**
	 * Load a theme.xml file.
	 *
	 * @param string $filename
	 * @param string $theme_name
	 * @param string $lang
	 * @return ?object
	 */
	public static function loadXML(string $filename, string $theme_name, string $lang = ''): ?object
	{
		$xml = simplexml_load_string(file_get_contents($filename));
		if ($xml === false)
		{
			return null;
		}

		$lang = $lang ?: (\Context::getLangType() ?: 'en');

		$info = new \stdClass;
		$info->name = $theme_name;
		$info->path = \Zittme\Framework\Theme::BASE_DIR . $theme_name . '/';
		$info->schema = trim(strval($xml['schema'] ?? '')) ?: '1.0';
		$info->supported = version_compare($info->schema, self::MAX_SCHEMA, '<=');

		$info->title = self::_getChildrenByLang($xml, 'title', $lang) ?: $theme_name;
		$info->description = self::_getChildrenByLang($xml, 'description', $lang);
		$info->version = trim(strval($xml->version));
		$info->license = trim(strval($xml->license));
		$info->license_link = trim(strval($xml->license['link'] ?? ''));
		$info->homepage = trim(strval($xml->link ?? ''));

		$info->author = [];
		foreach ($xml->author as $author)
		{
			$author_info = new \stdClass;
			$author_info->name = trim(strval($author));
			$author_info->email_address = trim(strval($author['email_address'] ?? ''));
			$author_info->homepage = trim(strval($author['link'] ?? ''));
			$info->author[] = $author_info;
		}

		// Compatibility constraints. Empty means unrestricted.
		$info->requires = new \stdClass;
		$info->requires->core_min = trim(strval($xml->requires['core_min'] ?? ''));
		$info->requires->core_max = trim(strval($xml->requires['core_max'] ?? ''));
		$info->requires->php_min = trim(strval($xml->requires['php_min'] ?? ''));
		$info->requires->php_max = trim(strval($xml->requires['php_max'] ?? ''));

		$info->components = self::_getComponents($xml, $lang);
		$info->apply = self::_getApply($xml);

		return $info;
	}

	/**
	 * Parse the component list. Paths are derived from type/target/name
	 * rather than declared in XML, since theme directories mirror the
	 * original component paths.
	 *
	 * @param \SimpleXMLElement $xml
	 * @param string $lang
	 * @return array
	 */
	protected static function _getComponents(\SimpleXMLElement $xml, string $lang): array
	{
		$components = [];
		if (!isset($xml->components->component))
		{
			return $components;
		}

		foreach ($xml->components->component as $node)
		{
			$item = new \stdClass;
			$item->type = trim(strval($node['type'] ?? ''));
			$item->name = trim(strval($node['name'] ?? ''));
			$item->target = trim(strval($node['target'] ?? ''));
			$item->mobile = in_array(strtolower(trim(strval($node['mobile'] ?? ''))), ['true', 'y', '1'], true);
			$item->guide = self::_getChildrenByLang($node, 'guide', $lang);
			$item->path = self::getComponentPath($item);

			if ($item->type === '' || $item->name === '' || $item->path === null)
			{
				continue;
			}

			$components[] = $item;
		}

		return $components;
	}

	/**
	 * Get the relative path of a component inside the theme directory.
	 *
	 * @param object $item
	 * @return ?string null if the type or name is invalid
	 */
	public static function getComponentPath(object $item): ?string
	{
		$name = $item->name ?? '';
		$target = $item->target ?? '';
		if (!preg_match('/^[a-zA-Z0-9_]+$/', $name))
		{
			return null;
		}

		$skin_dir = !empty($item->mobile) ? 'm.skins' : 'skins';

		switch ($item->type ?? '')
		{
			case 'module':
				return 'modules/' . $name;
			case 'addon':
				return 'addons/' . $name;
			case 'layout':
				return (!empty($item->mobile) ? 'm.layouts/' : 'layouts/') . $name;
			case 'widget':
				return 'widgets/' . $name;
			case 'editor-skin':
				return 'modules/editor/skins/' . $name;
			case 'editor-component':
				return 'modules/editor/components/' . $name;

			// Skins need a target module or widget to determine their location.
			case 'module-skin':
				return preg_match('/^[a-zA-Z0-9_]+$/', $target)
					? 'modules/' . $target . '/' . $skin_dir . '/' . $name : null;
			case 'widget-skin':
				return preg_match('/^[a-zA-Z0-9_]+$/', $target)
					? 'widgets/' . $target . '/' . $skin_dir . '/' . $name : null;
		}

		return null;
	}

	/**
	 * Parse the apply section. Anything not declared here is never
	 * touched when the theme is applied.
	 *
	 * @param \SimpleXMLElement $xml
	 * @return object
	 */
	protected static function _getApply(\SimpleXMLElement $xml): object
	{
		$apply = new \stdClass;
		$apply->view_mode = null;
		$apply->layout = null;
		$apply->mlayout = null;
		$apply->skins = [];
		$apply->mskins = [];

		if (!isset($xml->apply))
		{
			return $apply;
		}

		$mode = strtoupper(trim(strval($xml->apply->view['mode'] ?? '')));
		if (in_array($mode, ['R', 'Y', 'N'], true))
		{
			$apply->view_mode = $mode;
		}

		foreach (['layout' => 'layout', 'mlayout' => 'mlayout'] as $tag => $key)
		{
			if (isset($xml->apply->{$tag}))
			{
				$item = new \stdClass;
				$item->name = trim(strval($xml->apply->{$tag}['name'] ?? ''));
				$item->vars = self::_getVars($xml->apply->{$tag});
				if ($item->name !== '')
				{
					$apply->{$key} = $item;
				}
			}
		}

		foreach (['skin' => 'skins', 'mskin' => 'mskins'] as $tag => $key)
		{
			if (!isset($xml->apply->{$tag}))
			{
				continue;
			}
			foreach ($xml->apply->{$tag} as $node)
			{
				$item = new \stdClass;
				$item->module = trim(strval($node['module'] ?? ''));
				$item->name = trim(strval($node['name'] ?? ''));
				$item->colorset = trim(strval($node['colorset'] ?? ''));
				$item->vars = self::_getVars($node);
				if ($item->module !== '' && $item->name !== '')
				{
					$apply->{$key}[] = $item;
				}
			}
		}

		return $apply;
	}

	/**
	 * Parse <var name="..">value</var> children (skin/layout settings).
	 *
	 * @param \SimpleXMLElement $node
	 * @return array
	 */
	protected static function _getVars(\SimpleXMLElement $node): array
	{
		$vars = [];
		if (!isset($node->var))
		{
			return $vars;
		}
		foreach ($node->var as $var)
		{
			$name = trim(strval($var['name'] ?? ''));
			if ($name !== '')
			{
				$vars[$name] = trim(strval($var));
			}
		}
		return $vars;
	}
}
