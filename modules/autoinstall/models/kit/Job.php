<?php

namespace Zittme\Modules\Autoinstall\Models\Kit;

use Zittme\Framework\DB;
use Zittme\Framework\Storage;

/**
 * A kit installation job and its journal (installation snapshot).
 */
#[\AllowDynamicProperties]
class Job
{
	public const TABLES = ['autoinstall_kit_jobs', 'autoinstall_kit_items'];

	public const STATUS_READY = 'ready';
	public const STATUS_RUNNING = 'running';
	public const STATUS_FAILED = 'failed';
	public const STATUS_DONE = 'done';
	public const STATUS_REMOVING = 'removing';
	public const STATUS_REMOVED = 'removed';
	public const STATUS_ROLLEDBACK = 'rolledback';

	/**
	 * Work folders of jobs that were only previewed are removed after this many seconds.
	 */
	public const STALE_SECONDS = 86400;

	public $job_srl = 0;
	public $kit_id = '';
	public $kit_version = '';
	public $title = '';
	public $package_srl = 0;
	public $source = 'store';
	public $work_dir = '';
	public $signature = '';
	public $options;
	public $state;
	public $status = self::STATUS_READY;
	public $message = '';
	public $member_srl = 0;
	public $regdate = '';
	public $last_update = '';

	protected $_maps = null;

	public static function ensureTables(): void
	{
		$oDB = DB::getInstance();
		foreach (self::TABLES as $table)
		{
			if (!$oDB->isTableExists($table))
			{
				$oDB->createTable(\RX_BASEDIR . 'modules/autoinstall/schemas/kit/' . $table . '.xml');
			}
		}
	}

	public static function getWorkBase(): string
	{
		return \RX_BASEDIR . 'files/kits/';
	}

	public static function protectWorkBase(): void
	{
		$base = self::getWorkBase();
		if (!Storage::isDirectory($base))
		{
			Storage::createDirectory($base);
		}
		self::protectDirectory($base);
		self::cleanupStale();
	}

	/**
	 * Block web access to a work folder: .htaccess for Apache and an empty
	 * index.html against directory listing. Folder names are random, so the
	 * files inside cannot be reached on servers that ignore .htaccess either.
	 */
	public static function protectDirectory(string $dir): void
	{
		$dir = rtrim($dir, '/\\') . '/';
		if (!Storage::exists($dir . '.htaccess') || !Storage::exists($dir . 'index.html'))
		{
			Storage::protectDirectory($dir);
		}
	}

	/**
	 * Remove work folders that were left behind: a kit that was only previewed
	 * and never installed, a folder without a job, export folders and uploaded ZIP files.
	 * Jobs that started installing keep their folder so that they can resume.
	 */
	public static function cleanupStale(): void
	{
		$limit = time() - self::STALE_SECONDS;
		$base = self::getWorkBase();
		$oDB = DB::getInstance();
		if (!$oDB->isTableExists('autoinstall_kit_jobs'))
		{
			return;
		}

		$stmt = $oDB->query('SELECT job_srl, work_dir, status, last_update FROM autoinstall_kit_jobs');
		$known = [];
		foreach ($stmt ? $stmt->fetchAll() : [] as $row)
		{
			$known[basename((string)$row->work_dir)] = true;
			if ($row->status === self::STATUS_READY && $row->last_update && ztime($row->last_update) < $limit)
			{
				$job = self::get((int)$row->job_srl);
				if ($job && !count($job->getItems()))
				{
					$job->delete();
				}
			}
		}

		foreach (glob($base . 'job_*', \GLOB_ONLYDIR) ?: [] as $dir)
		{
			if (!isset($known[basename($dir)]) && filemtime($dir) < $limit)
			{
				Storage::deleteDirectory($dir);
			}
		}
		foreach (glob($base . 'export/*', \GLOB_ONLYDIR) ?: [] as $dir)
		{
			$state = $dir . '/_state.json';
			if ((is_file($state) ? filemtime($state) : filemtime($dir)) < $limit)
			{
				Storage::deleteDirectory($dir);
			}
		}
		foreach (glob($base . 'incoming/*.zip') ?: [] as $file)
		{
			if (filemtime($file) < $limit)
			{
				Storage::delete($file);
			}
		}
	}

	public static function create(object $manifest, string $work_dir, array $meta): self
	{
		self::ensureTables();
		$job = new self;
		$job->job_srl = getNextSequence();
		$job->kit_id = (string)$manifest->id;
		$job->kit_version = (string)$manifest->version;
		$job->title = Manifest::text($manifest->title ?? '');
		$job->package_srl = (int)($meta['package_srl'] ?? 0);
		$job->source = (string)($meta['source'] ?? 'store');
		$job->work_dir = $work_dir;
		$job->signature = (string)($meta['signature'] ?? '');
		$job->options = new \stdClass;
		if (preg_match('/^[0-9a-f]{64}$/', (string)($meta['manifest_sha256'] ?? '')))
		{
			$job->options->manifest_sha256 = (string)$meta['manifest_sha256'];
		}
		$job->state = (object)['step' => 0, 'cursor' => 0, 'done' => 0, 'total' => 0];
		$job->status = self::STATUS_READY;
		$job->member_srl = (int)($meta['member_srl'] ?? 0);

		$args = new \stdClass;
		foreach (['job_srl', 'kit_id', 'kit_version', 'title', 'package_srl', 'source', 'work_dir', 'signature', 'status', 'member_srl'] as $key)
		{
			$args->{$key} = $job->{$key};
		}
		$args->options = json_encode($job->options);
		$args->state = json_encode($job->state);
		$args->message = '';
		$output = executeQuery('autoinstall.insertKitJob', $args);
		return $output->toBool() ? self::get($job->job_srl) : $job;
	}

	public static function get(int $job_srl): ?self
	{
		self::ensureTables();
		$output = executeQuery('autoinstall.getKitJob', ['job_srl' => $job_srl]);
		if (!$output->toBool() || !$output->data)
		{
			return null;
		}
		return self::fromRow($output->data);
	}

	/**
	 * @return self[]
	 */
	public static function getList(?string $kit_id = null, ?array $statuses = null): array
	{
		self::ensureTables();
		$args = new \stdClass;
		if ($kit_id)
		{
			$args->kit_id = $kit_id;
		}
		if ($statuses)
		{
			$args->status = $statuses;
		}
		$output = executeQueryArray('autoinstall.getKitJobs', $args);
		$list = [];
		foreach ($output->data ?: [] as $row)
		{
			$list[] = self::fromRow($row);
		}
		return $list;
	}

	protected static function fromRow(object $row): self
	{
		$job = new self;
		foreach (get_object_vars($row) as $key => $val)
		{
			$job->{$key} = $val;
		}
		$job->job_srl = (int)$job->job_srl;
		$job->package_srl = (int)$job->package_srl;
		$job->member_srl = (int)$job->member_srl;
		$job->options = json_decode((string)$row->options) ?: new \stdClass;
		$job->state = json_decode((string)$row->state) ?: (object)['step' => 0, 'cursor' => 0, 'done' => 0, 'total' => 0];
		return $job;
	}

	public function save(): bool
	{
		$args = new \stdClass;
		$args->job_srl = $this->job_srl;
		$args->options = json_encode($this->options, JSON_UNESCAPED_UNICODE);
		$args->state = json_encode($this->state, JSON_UNESCAPED_UNICODE);
		$args->status = $this->status;
		$args->message = (string)$this->message;
		return executeQuery('autoinstall.updateKitJob', $args)->toBool();
	}

	public function getWorkPath(): string
	{
		return self::getWorkBase() . basename($this->work_dir) . '/';
	}

	public function hasWorkFiles(): bool
	{
		return Storage::exists($this->getWorkPath() . Archive::MANIFEST_FILE);
	}

	public function getManifest(): ?object
	{
		$raw = Storage::read($this->getWorkPath() . Archive::MANIFEST_FILE);
		$expected = (string)($this->options->manifest_sha256 ?? '');
		if ($raw && $expected !== '' && !hash_equals($expected, hash('sha256', $raw)))
		{
			return null;
		}
		$manifest = $raw ? json_decode($raw) : null;
		return is_object($manifest) ? $manifest : null;
	}

	public function readData(string $relative)
	{
		if (!Manifest::isDataPath($relative))
		{
			return null;
		}
		$raw = Storage::read($this->getWorkPath() . $relative);
		if ($raw === false || $raw === null)
		{
			return null;
		}
		if (!empty($this->options->manifest_sha256))
		{
			$manifest = $this->getManifest();
			$expected = strtolower((string)($manifest->integrity->files->{$relative} ?? ''));
			if (!$manifest || !preg_match('/^[0-9a-f]{64}$/', $expected) || !hash_equals($expected, hash('sha256', $raw)))
			{
				return null;
			}
		}
		return json_decode($raw);
	}

	public function getMediaBase(): string
	{
		return 'files/attach/kits/' . $this->kit_id . '_' . $this->job_srl . '/';
	}

	public function record(string $kind, string $ref, string $target, $before = null): bool
	{
		$args = new \stdClass;
		$args->item_srl = getNextSequence();
		$args->job_srl = $this->job_srl;
		$args->kind = $kind;
		$args->ref = $ref;
		$args->target = $target;
		$args->before_value = $before === null ? null : json_encode(Codec::pack($before), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$ok = executeQuery('autoinstall.insertKitItem', $args)->toBool();
		if ($ok && $this->_maps !== null && $ref !== '')
		{
			$this->_maps[$kind][$ref] = $target;
		}
		return $ok;
	}

	/**
	 * @return object[]
	 */
	public function getItems(?string $kind = null): array
	{
		$args = new \stdClass;
		$args->job_srl = $this->job_srl;
		if ($kind)
		{
			$args->kind = $kind;
		}
		$output = executeQueryArray('autoinstall.getKitItems', $args);
		return $output->data ?: [];
	}

	public function deleteItem(int $item_srl): void
	{
		executeQuery('autoinstall.deleteKitItem', ['item_srl' => $item_srl]);
	}

	/**
	 * ref => target maps per kind, rebuilt from the journal so that resuming skips finished work.
	 */
	public function getMap(string $kind): array
	{
		if ($this->_maps === null)
		{
			$this->_maps = [];
			foreach ($this->getItems() as $item)
			{
				if ($item->ref !== '')
				{
					$this->_maps[$item->kind][$item->ref] = $item->target;
				}
			}
		}
		return $this->_maps[$kind] ?? [];
	}

	public function mapped(string $kind, string $ref): ?string
	{
		$map = $this->getMap($kind);
		return isset($map[$ref]) ? (string)$map[$ref] : null;
	}

	public function countItems(): array
	{
		$counts = [];
		foreach ($this->getItems() as $item)
		{
			$counts[$item->kind] = ($counts[$item->kind] ?? 0) + 1;
		}
		return $counts;
	}

	public function delete(): void
	{
		executeQuery('autoinstall.deleteKitItems', ['job_srl' => $this->job_srl]);
		executeQuery('autoinstall.deleteKitJob', ['job_srl' => $this->job_srl]);
		if ($this->work_dir && Storage::isDirectory($this->getWorkPath()))
		{
			Storage::deleteDirectory($this->getWorkPath());
		}
	}

	public function isActive(): bool
	{
		return in_array($this->status, [self::STATUS_READY, self::STATUS_RUNNING, self::STATUS_FAILED, self::STATUS_DONE, self::STATUS_REMOVING], true);
	}
}
