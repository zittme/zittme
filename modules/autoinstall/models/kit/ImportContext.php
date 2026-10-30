<?php

namespace Zittme\Modules\Autoinstall\Models\Kit;

use Zittme\Framework\DB;

class ImportContext
{
	/** @var Job */
	public $job;
	/** @var object */
	public $manifest;
	public $member_srl = 0;
	protected $deadline;
	protected $columns = [];

	public function __construct(Job $job, object $manifest, float $budget_seconds = 8.0)
	{
		$this->job = $job;
		$this->manifest = $manifest;
		$this->deadline = microtime(true) + $budget_seconds;
		$this->member_srl = (int)$job->member_srl;
	}

	public function hasTime(): bool
	{
		return microtime(true) < $this->deadline;
	}

	public function getCursor(): int
	{
		return (int)($this->job->state->cursor ?? 0);
	}

	public function setCursor(int $cursor): void
	{
		$this->job->state->cursor = $cursor;
		$this->job->save();
	}

	public function setTotal(int $total): void
	{
		$this->job->state->total = $total;
	}

	public function id(string $kind, string $ref): ?string
	{
		return $this->job->mapped($kind, $ref);
	}

	public function moduleSrl(string $ref): int
	{
		return (int)($this->job->mapped('module', $ref) ?? 0);
	}

	public function mid(string $ref): string
	{
		$srl = $this->moduleSrl($ref);
		if (!$srl)
		{
			return '';
		}
		$info = \ModuleModel::getModuleInfoByModuleSrl($srl);
		return $info ? (string)$info->mid : '';
	}

	/**
	 * Replace lang codes and media tokens inside a string.
	 */
	public function rewrite(string $value): string
	{
		if (strpos($value, '$user_lang->') !== false)
		{
			$map = $this->job->getMap('lang');
			if ($map)
			{
				$value = preg_replace_callback('/\$user_lang->([a-zA-Z0-9_]+)/', function ($m) use ($map) {
					return '$user_lang->' . ($map[$m[1]] ?? $m[1]);
				}, $value);
			}
		}
		if (strpos($value, ExportContext::MEDIA_TOKEN) !== false)
		{
			$files = $this->job->getMap('filepath');
			$base = \RX_BASEURL . $this->job->getMediaBase();
			$value = preg_replace_callback('~' . preg_quote(ExportContext::MEDIA_TOKEN, '~') . '([A-Za-z0-9_\-/.]+)~', function ($m) use ($files, $base) {
				$rel = $m[1];
				if (strpos($rel, '..') !== false)
				{
					return '';
				}
				if (isset($files[$rel]))
				{
					return \RX_BASEURL . ltrim(preg_replace('!^\./!', '', $files[$rel]), '/');
				}
				return $base . $rel;
			}, $value);
		}
		return $value;
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

	/**
	 * Real column names of a table, used to whitelist incoming row keys.
	 */
	public function getColumns(string $table): array
	{
		if (!preg_match('/^[a-z0-9_]+$/', $table))
		{
			return [];
		}
		if (!isset($this->columns[$table]))
		{
			$this->columns[$table] = [];
			$oDB = DB::getInstance();
			if ($oDB->isTableExists($table))
			{
				$prefix = (string)config('db.master.prefix');
				$stmt = $oDB->getHandle()->query('SHOW COLUMNS FROM `' . $prefix . $table . '`');
				foreach ($stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [] as $col)
				{
					$this->columns[$table][] = $col['Field'];
				}
			}
		}
		return $this->columns[$table];
	}

	/**
	 * Insert a row into a module table and journal it.
	 */
	public function insertRow(string $table, string $pk, array $row, string $ref): bool
	{
		$columns = $this->getColumns($table);
		if (!$columns || !in_array($pk, $columns, true))
		{
			return false;
		}
		$data = [];
		foreach ($row as $key => $val)
		{
			if (in_array($key, $columns, true))
			{
				$data[$key] = is_bool($val) ? ($val ? 'Y' : 'N') : $val;
			}
		}
		$names = array_keys($data);
		$sql = 'INSERT INTO ' . $table . ' (`' . implode('`, `', $names) . '`) VALUES (' . ExportContext::inClause($names) . ')';
		$oDB = DB::getInstance();
		$stmt = $oDB->query($sql, array_values($data));
		if (!$stmt || $oDB->isError())
		{
			throw new \RuntimeException('kit row insert failed: ' . $table . ' ' . $oDB->getError()->getMessage());
		}
		return $this->job->record('row', $ref, $table . ':' . $pk . ':' . (int)$data[$pk]);
	}
}
