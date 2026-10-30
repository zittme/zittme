<?php

namespace Zittme\Modules\Autoinstall\Models\Kit;

use Zittme\Framework\DB;
use Zittme\Framework\Storage;

class ExportContext
{
	public const MEDIA_TOKEN = '%KIT_MEDIA%/';
	public const MEDIA_EXTENSIONS = 'jpe?g|png|gif|webp|avif|mp4|webm|ico';

	/** @var int[] module_srl => mid */
	public $modules = [];
	public $include_media = true;
	public $include_documents = true;
	/** @var array relative path under files/attach => absolute path */
	public $media = [];
	/** @var array lang code => true */
	public $lang_codes = [];
	protected $hosts = [];

	public function __construct(array $modules, array $hosts = [])
	{
		$this->modules = $modules;
		$this->hosts = array_values(array_filter(array_unique($hosts), 'strlen'));
	}

	public function hasModule(int $module_srl): bool
	{
		return isset($this->modules[$module_srl]);
	}

	public function moduleSrls(): array
	{
		return array_map('intval', array_keys($this->modules));
	}

	public static function ref(string $prefix, $id): string
	{
		return $prefix . (int)$id;
	}

	public static function rowRef(string $table, $pk): string
	{
		return 'x:' . $table . ':' . (int)$pk;
	}

	public function query(string $sql, array $args = []): array
	{
		$stmt = DB::getInstance()->query($sql, $args);
		if (!$stmt)
		{
			return [];
		}
		return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
	}

	public static function inClause(array $values): string
	{
		return $values ? implode(',', array_fill(0, count($values), '?')) : 'NULL';
	}

	protected function mediaPattern(): string
	{
		$host = '';
		if ($this->hosts)
		{
			$host = '(?:https?://(?:' . implode('|', array_map(function ($h) { return preg_quote($h, '~'); }, $this->hosts)) . ')(?::\d+)?)?';
		}
		return '~' . $host . '(?:\./|/)?(?<![A-Za-z0-9_\-])files/attach/([A-Za-z0-9_\-/.]+?\.(?:' . self::MEDIA_EXTENSIONS . '))(?![A-Za-z0-9_\-.])~i';
	}

	/**
	 * Rewrite one string: collect lang codes and replace attachment paths with media tokens.
	 */
	public function rewrite(string $value): string
	{
		if (strpos($value, '$user_lang->') !== false && preg_match_all('/\$user_lang->([a-zA-Z0-9_]+)/', $value, $m))
		{
			foreach ($m[1] as $code)
			{
				$this->lang_codes[$code] = true;
			}
		}
		if (strpos($value, 'files/attach/') === false)
		{
			return $value;
		}
		return preg_replace_callback($this->mediaPattern(), function ($m) {
			$rel = $m[1];
			if (strpos($rel, '..') !== false)
			{
				return $m[0];
			}
			$abs = \RX_BASEDIR . 'files/attach/' . $rel;
			if (!$this->include_media || !Storage::isFile($abs))
			{
				return $m[0];
			}
			$this->media[$rel] = $abs;
			return self::MEDIA_TOKEN . $rel;
		}, $value);
	}

	public function rewriteValue($value)
	{
		return Codec::mapStrings($value, function ($s) {
			return $this->rewrite($s);
		});
	}

	public function rewriteSerialized(?string $value): ?string
	{
		if ($value === null || $value === '')
		{
			return $value;
		}
		return Codec::mapSerialized($value, function ($s) {
			return $this->rewrite($s);
		});
	}
}
