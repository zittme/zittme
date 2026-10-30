<?php

namespace Zittme\Modules\Autoinstall\Models\Kit;

/**
 * KitImporter driven by a table definition. See docs/STARTER-KIT-SPEC.md chapter 7.
 */
abstract class TableKitImporter implements KitImporter
{
	/**
	 * @return array table => ['pk' => string, 'scope' => 'all'|'module', 'parent' => [col => table], 'refs' => [col => table|'module'|'member'], 'blank' => string[], 'reset' => [col => value], 'where' => string]
	 */
	abstract protected function tables(): array;

	abstract protected function title(): string;

	public function getKitInfo(): array
	{
		return ['title' => $this->title(), 'tables' => array_keys($this->tables())];
	}

	protected function exportRow(string $table, array $row): array
	{
		return $row;
	}

	protected function importRow(string $table, array $row, ImportContext $ctx): array
	{
		return $row;
	}

	public function exportKit(ExportContext $ctx): array
	{
		$out = [];
		$exported = [];
		foreach ($this->tables() as $table => $def)
		{
			$pk = $def['pk'];
			$where = [];
			$args = [];
			if (($def['scope'] ?? '') === 'module')
			{
				$srls = $ctx->moduleSrls();
				$where[] = 'module_srl IN (' . ExportContext::inClause($srls) . ')';
				$args = array_merge($args, $srls);
			}
			foreach ($def['parent'] ?? [] as $col => $parent_table)
			{
				$ids = array_keys($exported[$parent_table] ?? []);
				$where[] = '`' . $col . '` IN (' . ExportContext::inClause($ids) . ')';
				$args = array_merge($args, $ids);
			}
			if (!empty($def['where']))
			{
				$where[] = '(' . $def['where'] . ')';
			}
			$sql = 'SELECT * FROM ' . $table . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY `' . $pk . '` ASC';
			$rows = [];
			foreach ($ctx->query($sql, $args) as $row)
			{
				$exported[$table][(int)$row[$pk]] = true;
				$row = $this->exportRow($table, $row);
				foreach ($def['blank'] ?? [] as $col)
				{
					if (array_key_exists($col, $row))
					{
						$row[$col] = preg_match('/_srl$/', $col) ? 0 : '';
					}
				}
				foreach ($def['reset'] ?? [] as $col => $val)
				{
					if (array_key_exists($col, $row))
					{
						$row[$col] = $val;
					}
				}
				foreach ($def['parent'] ?? [] as $col => $parent_table)
				{
					$row[$col] = ExportContext::rowRef($parent_table, $row[$col]);
				}
				foreach ($def['refs'] ?? [] as $col => $target)
				{
					if (!array_key_exists($col, $row))
					{
						continue;
					}
					$val = (int)$row[$col];
					if ($target === 'member')
					{
						$row[$col] = 0;
					}
					elseif ($target === 'module')
					{
						$row[$col] = ($val && $ctx->hasModule($val)) ? ExportContext::ref('m', $val) : 0;
					}
					else
					{
						$row[$col] = $val ? ExportContext::rowRef($target, $val) : 0;
					}
				}
				$row[$pk] = ExportContext::rowRef($table, $row[$pk]);
				foreach ($row as $col => $val)
				{
					if (is_string($val))
					{
						$row[$col] = $ctx->rewrite($val);
					}
				}
				$rows[] = $row;
			}
			$out[$table] = $rows;
		}
		return ['tables' => $out];
	}

	public function importKit(array $data, ImportContext $ctx): bool
	{
		$tables = $this->tables();
		$source = (array)($data['tables'] ?? []);
		$flat = [];
		foreach ($tables as $table => $def)
		{
			foreach ((array)($source[$table] ?? []) as $row)
			{
				$flat[] = [$table, (array)$row];
			}
		}
		$ctx->setTotal(count($flat));
		$cursor = $ctx->getCursor();
		$count = count($flat);
		for ($i = $cursor; $i < $count; $i++)
		{
			if (!$ctx->hasTime())
			{
				$ctx->setCursor($i);
				return false;
			}
			list($table, $row) = $flat[$i];
			$def = $tables[$table];
			$pk = $def['pk'];
			$ref = (string)($row[$pk] ?? '');
			if (!preg_match('/^x:' . preg_quote($table, '/') . ':\d+$/', $ref) || $ctx->id('row', $ref) !== null)
			{
				continue;
			}
			$row[$pk] = getNextSequence();
			foreach ($def['parent'] ?? [] as $col => $parent_table)
			{
				$row[$col] = self::resolveRow($ctx, (string)($row[$col] ?? ''));
			}
			foreach ($def['refs'] ?? [] as $col => $target)
			{
				if (!array_key_exists($col, $row))
				{
					continue;
				}
				if ($target === 'member')
				{
					$row[$col] = 0;
				}
				elseif ($target === 'module')
				{
					$row[$col] = is_string($row[$col]) && $row[$col] !== '' ? $ctx->moduleSrl($row[$col]) : 0;
				}
				else
				{
					$row[$col] = is_string($row[$col]) ? self::resolveRow($ctx, $row[$col]) : 0;
				}
			}
			foreach ($row as $col => $val)
			{
				if (is_string($val))
				{
					$row[$col] = $ctx->rewrite($val);
				}
				elseif (is_array($val) || is_object($val))
				{
					$row[$col] = json_encode($val, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
				}
			}
			$row = $this->importRow($table, $row, $ctx);
			if (!$ctx->insertRow($table, $pk, $row, $ref))
			{
				throw new \RuntimeException('kit row insert failed: ' . $table);
			}
		}
		$ctx->setCursor($count);
		return true;
	}

	public static function resolveRow(ImportContext $ctx, string $ref): int
	{
		if ($ref === '' || $ref === '0')
		{
			return 0;
		}
		$target = $ctx->id('row', $ref);
		if ($target === null)
		{
			return 0;
		}
		$parts = explode(':', $target);
		return (int)end($parts);
	}
}
