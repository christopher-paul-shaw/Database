<?php
namespace Gt\Database\Migration;

use DirectoryIterator;
use Gt\Database\Client;
use Gt\Database\Connection\Settings;
use Gt\Database\DatabaseException;
use SplFileInfo;

class Migrator {
	const COLUMN_QUERY_NUMBER = "query_number";
	const COLUMN_QUERY_HASH = "query_hash";
	const COLUMN_MIGRATED_AT = "migrated_at";
	const DATA_DIRECOTRY = "data";

	protected $dataSource;
	protected $schema;
	protected $dbClient;
	protected $path;
	protected $tableName;

	public function __construct(
		Settings $settings,
		string $path,
		string $tableName = "_migration",
		bool $forced = false
	) {
		$this->schema = $settings->getSchema();
		$this->path = $path;
		$this->tableName = $tableName;
		$this->dataSource = $settings->getDataSource();

		if($this->dataSource !== Settings::DRIVER_SQLITE) {
			$settings = $settings->withoutSchema(); // @codeCoverageIgnore
		}

		$this->dbClient = new Client($settings);

		if($forced) {
			$this->deleteAndRecreateSchema();
		}

		$this->selectSchema();
	}

	public function checkMigrationTableExists():bool {
		switch($this->dataSource) {
		case Settings::DRIVER_SQLITE:
			$result = $this->dbClient->executeSql(
				"select name from sqlite_master "
				. "where type=? "
				. "and name like ?",[
					"table",
					$this->tableName,
				]
			);
			break;

		default:
// @codeCoverageIgnoreStart
			$result = $this->dbClient->executeSql(
				"show tables like ?",
				[
					$this->tableName
				]
			);
			break;
// @codeCoverageIgnoreEnd
		}

		return !empty($result->fetch());
	}

	public function createMigrationTable():void {
		$this->dbClient->executeSql(implode("\n", [
			"create table `{$this->tableName}` (",
			"`" . self::COLUMN_QUERY_NUMBER . "` int primary key,",
			"`" . self::COLUMN_QUERY_HASH . "` varchar(32) not null,",
			"`" . self::COLUMN_MIGRATED_AT . "` datetime not null )",
		]));
	}

	public function getMigrationCount():int {
		try {
			$result = $this->dbClient->executeSql("select `"
				. self::COLUMN_QUERY_NUMBER
				. "` from `{$this->tableName}` "
				. "order by `" . self::COLUMN_QUERY_NUMBER . "` desc"
			);
			$row = $result->fetch();
		}
		catch(DatabaseException $exception) {
			return 0;
		}

		return $row->{self::COLUMN_QUERY_NUMBER} ?? 0;
	}

	public function getMigrationFileList(bool $withData = false):array {
		if(!is_dir($this->path)) {
			throw new MigrationDirectoryNotFoundException(
				$this->path
			);
		}

		$fileList = [];

		foreach(new DirectoryIterator($this->path) as $i => $fileInfo) {
			if($fileInfo->isDot()
			|| $fileInfo->getExtension() !== "sql") {
				continue;
			}

			$pathName = $fileInfo->getPathname();
			$fileList []= $pathName;
		}

		sort($fileList);
		return $fileList;
	}

	public function getDataFileList():array {
		$dataPath = implode(DIRECTORY_SEPARATOR, [
			$this->path,
			self::DATA_DIRECOTRY,
		]);

		$fileList = scandir($dataPath);
		sort($fileList);

		$fileList = array_filter($fileList, function($path) {
			return $path[0] !== ".";
		});
		$fileList = array_map(function($path) {
			return implode(DIRECTORY_SEPARATOR, [
				self::DATA_DIRECOTRY,
				$path,
			]);
		}, $fileList);

		return array_values($fileList);
	}

	public function mergeMigrationDataFileList(
		array $migrationFileList,
		array $dataFileList
	):array {
		$merged = [];
		$i = 0;

		foreach($dataFileList as $dataFile) {
			$dataFileNum = $this->extractNumberFromFilename($dataFile);

			while($i < $dataFileNum) {
				$merged []= $migrationFileList[$i];
				$i++;
			}

			$merged []= $dataFile;
		}

		return $merged;
	}

	public function checkFileListOrder(array $fileList):void {
		$counter = 0;
		$sequence = [];

		foreach($fileList as $file) {
			$counter++;
			$migrationNumber = $this->extractNumberFromFilename($file);
			$sequence []= $migrationNumber;

			if($counter !== $migrationNumber) {
				throw new MigrationSequenceOrderException(
					"Missing: $counter"
				);
			}
		}
	}

	public function checkIntegrity(
		array $migrationFileList,
		int $migrationCount = null
	):int {
		foreach($migrationFileList as $i => $file) {
			$fileNumber = $i + 1;
			$md5 = md5_file($file);

			if(is_null($migrationCount)
			|| $fileNumber <= $migrationCount) {
				$result = $this->dbClient->executeSql(implode("\n", [
					"select `" . self::COLUMN_QUERY_HASH . "`",
					"from `{$this->tableName}`",
					"where `" . self::COLUMN_QUERY_NUMBER . "` = ?",
					"limit 1",
				]), [$fileNumber]);

				$hashInDb = ($result->fetch())->{self::COLUMN_QUERY_HASH};

				if($hashInDb !== $md5) {
					throw new MigrationIntegrityException($file);
				}
			}
		}

		return $fileNumber;
	}

	protected function extractNumberFromFilename(string $pathName):int {
		$file = new SplFileInfo($pathName);
		$filename = $file->getFilename();
		preg_match("/(\d+)-?.*\.sql/", $filename, $matches);

		if(!isset($matches[1])) {
			throw new MigrationFileNameFormatException($filename);
		}

		return (int)$matches[1];
	}

	public function performMigration(
		array $migrationFileList,
		int $existingMigrationCount = 0
	):int {
		foreach($migrationFileList as $i => $file) {
			$dataDirectory = self::DATA_DIRECOTRY . DIRECTORY_SEPARATOR;

			$fileNumber = $i + 1;

			if($fileNumber <= $existingMigrationCount) {
				continue;
			}

			try {
				echo "Migration $fileNumber: `$file`." . PHP_EOL;
				$sql = file_get_contents($file);
				$md5 = md5_file($file);
				$this->dbClient->executeSql($sql);
				$this->recordMigrationSuccess($fileNumber, $md5);
			}
			catch(\Exception $exception) {
				echo "Error performing migration $fileNumber.";
				echo PHP_EOL;
				echo $exception->getMessage();
				echo PHP_EOL;
				exit(1);
			}
		}

		return $fileNumber;
	}

	/**
	 * @codeCoverageIgnore
	 */
	protected function selectSchema() {
// SQLITE databases represent their own schema.
		if($this->dataSource === Settings::DRIVER_SQLITE) {
			return;
		}

		$schema = $this->schema;

		try {
			$this->dbClient->executeSql(
				"create schema if not exists `$schema`"
			);
			$this->dbClient->executeSql(
				"use `$schema`"
			);
		}
		catch(DatabaseException $exception) {
			echo "Error selecting `$schema`." . PHP_EOL;
			echo $exception->getMessage() . PHP_EOL;
			exit(1);
		}
	}

	protected function recordMigrationSuccess(int $number, string $hash) {
		try {
			$now = "now()";

			if($this->dataSource === Settings::DRIVER_SQLITE) {
				$now = "datetime('now')";
			}

			$this->dbClient->executeSql(implode("\n", [
				"insert into `{$this->tableName}` (",
				"`" . self::COLUMN_QUERY_NUMBER . "`, ",
				"`" . self::COLUMN_QUERY_HASH . "`, ",
				"`" . self::COLUMN_MIGRATED_AT . "` ",
				") values (",
				"?, ?, $now",
				")",
			]), [$number, $hash]);
		}
		catch(\Exception $exception) {
			echo "Error storing migration progress in database table "
				. $this->tableName;
			echo PHP_EOL;
			echo $exception->getMessage();
			echo PHP_EOL;
			exit(1);
		}
	}

	/**
	 * @codeCoverageIgnore
	 */
	protected function deleteAndRecreateSchema() {
		if($this->dataSource === Settings::DRIVER_SQLITE) {
			return;
		}

		try {
			$this->dbClient->executeSql(
				"drop schema if exists `{$this->schema}`"
			);
			$this->dbClient->executeSql(
				"create schema if not exists `{$this->schema}`"
			);
		}
		catch(\Exception $exception) {
			echo "Error recreating schema `{$this->schema}`." . PHP_EOL;
			echo $exception->getMessage() . PHP_EOL;
			exit(1);
		}
	}
}