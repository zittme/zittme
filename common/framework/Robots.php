<?php

namespace Zittme\Framework;

/**
 * The robots.txt class.
 *
 * Builds robots.txt from the crawler settings and the site's own rules.
 */
class Robots
{
	/**
	 * Crawlers that collect content to train models.
	 *
	 * Blocking these does not affect how often a site is quoted.
	 */
	protected static $_train_agents = array(
		'GPTBot',
		'ClaudeBot',
		'Google-Extended',
		'Applebot-Extended',
		'meta-externalagent',
		'CCBot',
		'Bytespider',
		'Diffbot',
		'Omgilibot',
	);

	/**
	 * Crawlers that read a page while answering a question.
	 *
	 * Blocking these removes the site from AI answers.
	 */
	protected static $_answer_agents = array(
		'ChatGPT-User',
		'Claude-User',
		'Perplexity-User',
		'DuckAssistBot',
	);

	/**
	 * Crawlers that index pages for AI search results.
	 */
	protected static $_search_agents = array(
		'OAI-SearchBot',
		'Claude-SearchBot',
		'PerplexityBot',
	);

	/**
	 * Return the path of robots.txt inside the web root.
	 *
	 * @return string
	 */
	public static function getPath(): string
	{
		return \RX_BASEDIR . 'robots.txt';
	}

	/**
	 * Read the current robots.txt, or an empty string.
	 *
	 * @return string
	 */
	public static function read(): string
	{
		$path = self::getPath();
		return file_exists($path) ? (string)file_get_contents($path) : '';
	}

	/**
	 * Build the content of robots.txt.
	 *
	 * @return string
	 */
	public static function build(): string
	{
		$lines = array();

		foreach (self::getBlockedAgents() as $agent)
		{
			$lines[] = 'User-agent: ' . $agent;
			$lines[] = 'Disallow: /';
			$lines[] = '';
		}

		$custom = trim((string)Config::get('seo.robots_custom'));
		if ($custom !== '')
		{
			$lines[] = $custom;
			$lines[] = '';
		}

		if (!count($lines))
		{
			$lines[] = 'User-agent: *';
			$lines[] = 'Disallow:';
			$lines[] = '';
		}

		if (Config::get('seo.sitemap') && Config::get('seo.robots_sitemap') !== false)
		{
			$sitemap_url = rtrim((string)Config::get('url.default'), '/') . '/sitemap.xml';
			$has_sitemap = preg_match_all('/^[ \t]*sitemap[ \t]*:[ \t]*(\S+)/im', $custom, $matches) &&
				in_array(strtolower($sitemap_url), array_map('strtolower', $matches[1]), true);
			if (!$has_sitemap)
			{
				$lines[] = 'Sitemap: ' . $sitemap_url;
				$lines[] = '';
			}
		}

		return implode("\n", $lines);
	}

	/**
	 * Return the list of user agents that should be blocked.
	 *
	 * @return array
	 */
	public static function getBlockedAgents(): array
	{
		$result = array();
		if (!Config::get('seo.ai_train'))
		{
			$result = array_merge($result, self::$_train_agents);
		}
		if (Config::get('seo.ai_answer') === false)
		{
			$result = array_merge($result, self::$_answer_agents);
		}
		if (Config::get('seo.ai_search') === false)
		{
			$result = array_merge($result, self::$_search_agents);
		}
		return $result;
	}

	/**
	 * Write robots.txt to the web root.
	 *
	 * @return bool
	 */
	public static function write(): bool
	{
		return \FileHandler::writeFile(self::getPath(), self::build()) !== false;
	}
}
