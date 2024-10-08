<?php
namespace Burzum\FileStorage\Shell;

use Cake\Core\Configure;
use Cake\Console\Shell;
use Cake\Event\EventInterface;
use Cake\Event\EventManager;
use Cake\ORM\TableRegistry;
use Burzum\FileStorage\Storage\StorageManager;
use Exception;

/**
 * Image Version Shell
 *
 * @author Florian Krämer
 * @copyright 2012 - 2017 Florian Krämer
 * @license MIT
 */
class ImageVersionShell extends Shell {

	/**
	 * Storage Table Object
	 *
	 * @var \Cake\ORM\Table
	 */
	public $Table = null;

	/**
	 * Limit
	 *
	 * @var int
	 */
	public $limit = 10;

	/**
	 * @inheritDoc
	 */
	public function getOptionParser() {
		$parser = parent::getOptionParser();
		$parser->description([
			__d('file_storage', 'Shell command for generating and removing image versions.')
		]);
		$parser->addOption('storageTable', [
			'short' => 's',
			'help' => __d('file_storage', 'The storage table for image processing you want to use.')
		]);
		$parser->addOption('limit', [
			'short' => 'l',
			'help' => __d('file_storage', 'Limits the amount of records to be processed in one batch')
		]);
		$parser->addSubcommands([
			'generate' => [
				'help' => __d('file_storage', '<model> <version> Generate a new image version'),
				'parser' => [
					'arguments' => [
						'model' => [
							'help' => __d('file_storage', 'Value of the model property of the images to generate'),
							'required' => true,
						],
						'version' => [
							'help' => __d('file_storage', 'Image version to generate'),
							'required' => true,
						],
					],
					'options' => [
						'storageTable' => [
							'short' => 's',
							'help' => __d('file_storage', 'The storage table for image processing you want to use.'),
						],
						'limit' => [
							'short' => 'l',
							'help' => __d('file_storage', 'Limits the amount of records to be processed in one batch'),
						],
						'keep-old-versions' => [
							'short' => 'k',
							'help' => __d('file_storage', 'Use this switch if you do not want to overwrite existing versions.'),
							'boolean' => true
						]
					],
				],
			],
			'remove' => [
				'help' => __d('file_storage', '<model> <version> Remove an new image version'),
				'parser' => [
					'arguments' => [
						'model' => [
							'help' => __d('file_storage', 'Value of the model property of the images to remove'),
							'required' => true,
						],
						'version' => [
							'help' => __d('file_storage', 'Image version to remove'),
							'required' => true,
						],
					],
					'options' => [
						'storageTable' => [
							'short' => 's',
							'help' => __d('file_storage', 'The storage table for image processing you want to use.'),
						],
						'limit' => [
							'short' => 'l',
							'help' => __d('file_storage', 'Limits the amount of records to be processed in one batch'),
						],
					],
				],
			],
			'regenerate' => [
				'help' => __d('file_storage', '<model> Generates all image versions.'),
				'parser' => [
					'arguments' => [
						'model' => [
							'help' => __d('file_storage', 'Value of the model property of the images to generate'),
							'required' => true,
						],
					],
					'options' => [
						'storageTable' => [
							'short' => 's',
							'help' => __d('file_storage', 'The storage table for image processing you want to use.'),
						],
						'limit' => [
							'short' => 'l',
							'help' => __d('file_storage', 'Limits the amount of records to be processed in one batch'),
						],
						'keep-old-versions' => [
							'short' => 'k',
							'help' => __d('file_storage', 'Use this switch if you do not want to overwrite existing versions.'),
							'boolean' => true
						]
					],
				],
			],
		]);

		return $parser;
	}

	/**
	 * @inheritDoc
	 */
	public function startup() {
		parent::startup();

		$storageTable = 'Burzum/FileStorage.ImageStorage';
		if (isset($this->params['storageTable'])) {
			$storageTable = $this->params['storageTable'];
		}

		$this->Table = TableRegistry::get($storageTable);

		if (isset($this->params['limit'])) {
			if (!is_numeric($this->params['limit'])) {
				$this->abort(__d('file_storage', '--limit must be an integer!'));
			}
			$this->limit = $this->params['limit'];
		}
	}

	/**
	 * Regenerate image versions
	 *
	 * @return void
	 */
	public function regenerate() {
		$operations = Configure::read('FileStorage.imageSizes.' . $this->args[0]);
		$options = [
			'overwrite' => !$this->params['keep-old-versions']
		];

		if (empty($operations)) {
			$this->abort(__d('file_storage', 'Invalid table or version.'));
		}

		foreach ($operations as $version => $operation) {
			try {
				$this->_loop($this->command, $this->args[0], array($version => $operation), $options);
			} catch (Exception $e) {
				$this->abort($e->getMessage());
			}
		}
	}

	/**
	 * Generate a given image version.
	 *
	 * @param string $model
	 * @param string $version
	 * @return void
	 */
	public function generate($model, $version) {
		$operations = Configure::read('FileStorage.imageSizes.' . $model . '.' . $version);
		$options = [
			'overwrite' => !$this->params['keep-old-versions']
		];

		if (empty($operations)) {
			$this->abort(__d('file_storage', 'Invalid table or version.'));
		}

		try {
			$this->_loop('generate', $model, array($version => $operations), $options);
		} catch (Exception $e) {
			$this->abort($e->getMessage());
		}
	}

	/**
	 * Remove a given image version.
	 *
	 * @param string $model
	 * @param string $version
	 * @return void
	 */
	public function remove($model, $version) {
		$operations = Configure::read('FileStorage.imageSizes.' . $model . '.' . $version);

		if (empty($operations)) {
			$this->abort(__d('file_storage', 'Invalid table or version.'));
		}

		try {
			$this->_loop('remove', $model, array($version => $operations));
		} catch (Exception $e) {
			$this->abort($e->getMessage());
		}
	}

	/**
	 * Loops through image records and performs requested operation on them.
	 *
	 * @param string $action
	 * @param $model
	 * @param array $operations
	 * @return void
	 */
	protected function _loop($action, $model, $operations = [], $options = []) {
		if (!in_array($action, array('generate', 'remove', 'regenerate'))) {
			$this->abort('Invalid action');
		}

		$totalImageCount = $this->_getCount($model);

		if ($totalImageCount === 0) {
			$this->abort(__d('file_storage', 'No Images for model {0} found', $model));
		}

		$this->out(__d('file_storage', '{0} image file(s) will be processed' . "\n", $totalImageCount));

		$offset = 0;
		$limit = $this->limit;

		do {
			$images = $this->_getRecords($model, $limit, $offset);
			if (!empty($images)) {
				foreach ($images as $image) {
					$Storage = StorageManager::getAdapter($image->adapter);
					if ($Storage === false) {
						$this->warn(__d('file_storage', 'Cant load adapter config {0} for record {1}', $image->adapter, $image->id));
					} else {
						$payload = array(
							'record' => $image,
							'storage' => $Storage,
							'operations' => $operations,
							'versions' => array_keys($operations),
							'table' => $this->Table,
							'options' => $options
						);

						if ($action === 'generate' || $action === 'regenerate') {
							$Event = new Event('ImageVersion.createVersion', $this->Table, $payload);
							EventManager::instance()->dispatch($Event);
						}

						if ($action === 'remove') {
							$Event = new Event('ImageVersion.removeVersion', $this->Table, $payload);
							EventManager::instance()->dispatch($Event);
						}

						$this->out(__('{0} processed', $image->get('id')));
						$this->verbose('Path: ' . $image->get('path'));
						$this->verbose('Adapter Config: ' . $image->get('adapter'));
						$this->verbose($this->nl());
					}
				}
			}
			$offset += $limit;
		} while ($images->count() > 0);
	}

	/**
	 * Gets the amount of images for a model in the DB.
	 *
	 * @param string $identifier
	 * @param array $extensions
	 * @return int
	 */
	protected function _getCount($identifier, array $extensions = ['jpg', 'png', 'jpeg']) {
		return $this->Table
			->find()
			->where(['model' => $identifier])
			->andWhere(['extension IN' => $extensions])
			->count();
	}

	protected function _getRecords($identifier, $limit, $offset, array $extensions = ['jpg', 'png', 'jpeg']) {
		return $this->Table
			->find()
			->where(['model' => $identifier])
			->andWhere(['extension IN' => $extensions])
			->limit($limit)
			->offset($offset)
			->all();
	}
}
