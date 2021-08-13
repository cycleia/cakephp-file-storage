<?php
namespace Burzum\FileStorage\Model\Table;

use ArrayAccess;
use Burzum\FileStorage\Storage\PathBuilder\PathBuilderTrait;
use Burzum\FileStorage\Storage\StorageTrait;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\Filesystem\File;
use Cake\Log\LogTrait;
use Cake\ORM\Table;

/**
 * FileStorageTable
 *
 * @author Florian Krämer
 * @copyright 2012 - 2017 Florian Krämer
 * @license MIT
 */
class FileStorageTable extends Table {

	use LogTrait;
	use PathBuilderTrait;
	use StorageTrait;

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'FileStorage';

	/**
	 * The record that was deleted
	 * This gets set in the beforeDelete() callback so that the data is available
	 * in the afterDelete() callback
	 *
	 * @var array
	 */
	public $record = [];

	/**
	 * Default Storage Adapter to use when unspecified.
	 *
	 * @var string
	 */
	protected $_defaultAdapter = 'Local';

	/**
	 * Initialize
	 *
	 * @param array $config
	 * @return void
	 */
	public function initialize(array $config): void {
		parent::initialize($config);
		$this->setPrimaryKey('id');
		$this->addBehavior('Timestamp');
		$this->setDisplayField('filename');
		$this->setTable('file_storage');
	}

	/**
	 * Renews the FileUpload behavior with a new configuration
	 *
	 * @param array $options
	 * @return void
	 */
	public function configureUploadValidation($options) {
		$this->removeBehavior('Burzum/FileStorage.UploadValidator');
		$this->addBehavior('Burzum/FileStorage.UploadValidator', $options);
	}

	/**
	 * beforeSave callback
	 *
	 * @param \Cake\Event\Event $event
	 * @param \ArrayAccess $data
	 * @return void
	 */
	public function beforeMarshal(EventInterface $event, ArrayAccess $data) {		
		$copy = $data;
		// Someties the data array does not have the parent element 'file'
		// So we deal with that situation here.
		if(isset($data['file'])) {
			$copy = $data['file'];
		}

		if (!empty($copy['tmp_name'])) {
			$File = new File($copy['tmp_name']);
			$data['filesize'] = $File->size();
			$data['mime_type'] = $File->mime();
		}
		if (!empty($copy['name'])) {
			$data['extension'] = pathinfo($copy['name'], PATHINFO_EXTENSION);
			$data['filename'] = $copy['name'];
		}
	}

	/**
	 * beforeSave callback
	 *
	 * @param \Cake\Event\Event $event
	 * @param \Cake\Datasource\EntityInterface $entity
	 * @param array $options
	 * @return boolean true on success
	 */
	public function beforeSave(EventInterface $event, EntityInterface $entity, $options) {
		$this->_checkEntityBeforeSave($entity);
		$Event = $this->dispatchEvent('FileStorage.beforeSave', array(
			'record' => $entity,
			'storage' => $this->storageAdapter($entity->adapter)
		));
		if ($Event->isStopped()) {
			return false;
		}
		return true;
	}

	/**
	 * _checkEntityBeforeSave
	 *
	 * @param \Cake\Datasource\EntityInterface $entity
	 * @return void
	 */
	protected function _checkEntityBeforeSave(EntityInterface &$entity) {
		if ($entity->isNew()) {
			if (empty($entity->model)) {
				$entity->model = $this->getAlias();
			}
			if (empty($entity->adapter)) {
				$entity->adapter = $this->_defaultAdapter;
			}
		}
	}

	/**
	 * afterSave callback
	 *
	 * @param \Cake\Event\Event $event
	 * @param \Cake\Datasource\EntityInterface $entity
	 * @param array $options
	 * @return boolean
	 */
	public function afterSave(EventInterface $event, EntityInterface $entity, $options) {
		$this->dispatchEvent('FileStorage.afterSave', [
			'record' => $entity,
			// 'created' => $entity->isNew(),
			'created' => $event->getData('entity')->isNew(),
			'storage' => $this->storageAdapter($entity->adapter)
		]);
		$this->deleteOldFileOnSave($entity);
		return true;
	}

	/**
	 * Get a copy of the actual record before we delete it to have it present in afterDelete
	 *
	 * @param \Cake\Event\Event $event
	 * @param \Cake\Datasource\EntityInterface $entity
	 * @return boolean
	 */
	public function beforeDelete(EventInterface $event, EntityInterface $entity) {
		$this->record = $this->find()
			->contain([])
			->where([
				$this->getAlias() . '.' . $this->getPrimaryKey() => $entity->{$this->getPrimaryKey()}
			])
			->first();

		if (empty($this->record)) {
			return false;
		}

		return true;
	}

	/**
	 * afterDelete callback
	 *
	 * @param \Cake\Event\Event $event
	 * @param \Cake\Datasource\EntityInterface $entity
	 * @param array $options
	 * @return boolean
	 */
	public function afterDelete(EventInterface $event, EntityInterface $entity, $options) {
		$event = $this->dispatchEvent('FileStorage.afterDelete', [
			'record' => $entity,
			'storage' => $this->storageAdapter($entity->adapter)
		]);
		if ($event->isStopped()) {
			return $event->getResult();
		}
		try {
			$Storage = $this->storageAdapter($entity->adapter);
			$Storage->delete($entity->path);
			return true;
		} catch (\Exception $e) {
			$this->log($e->getMessage());
		}
		return false;
	}

	/**
	 * Deletes an old file to replace it with the new one if an old id was passed.
	 * Thought to be called in Model::afterSave() but can be used from any other
	 * place as well like Model::beforeSave() as long as the field data is present.
	 * The old id has to be the UUID of the file_storage record that should be deleted.
	 *
	 * @param \Cake\Datasource\EntityInterface $entity
	 * @param string $oldIdField Name of the field in the data that holds the old id.
	 * @return boolean Returns true if the old record was deleted
	 */
	public function deleteOldFileOnSave(EntityInterface $entity, $oldIdField = 'old_file_id') {
		if (!empty($entity->{$oldIdField}) && $entity->model) {
			$oldEntity = $this->find()
				->contain([])
				->where([
					$this->getAlias() . '.' . $this->getPrimaryKey() => $entity->{$oldIdField}, 'model' => $entity->model
				])
				->first();

			if (!empty($oldEntity)) {
				return $this->delete($oldEntity);
			}
		}
		return false;
	}

	/**
	 * @inheritDoc
	 */
	public function dispatchEvent(string $name, ?array $data = null, ?object $subject = null): EventInterface {
		$data['table'] = $this;
		return parent::dispatchEvent($name, $data, $subject);
	}

	/**
	 * @deprecated Use storageAdapter() instead.
	 */
	public function getStorageAdapter($configName, $renewObject = false) {
		return $this->storageAdapter($configName, $renewObject);
	}
}
