<?php

namespace spaceonfire\Restify\Executors;

use Emonkak\HttpException\InternalServerErrorHttpException;
use Emonkak\HttpException\NotFoundHttpException;
use Exception;

class SalePaySystemRest implements IExecutor {
	use RestTrait {
		buildSchema as private _buildSchema;
	}

	private $entity = 'Bitrix\Sale\Internals\PaySystemActionTable';

	/**
	 * SalePaySystemRest constructor
	 * @param array $options executor options
	 * @throws \Bitrix\Main\LoaderException
	 * @throws InternalServerErrorHttpException
	 * @throws Exception
	 */
	public function __construct($options) {
		$this->loadModules([
			'sale',
		]);
		$this->checkEntity();
		$this->setSelectFieldsFromEntityClass();
		$this->setPropertiesFromArray($options);
		$this->registerBasicTransformHandler();
		$this->buildSchema();
	}

	private function buildSchema() {
		$this->_buildSchema();
		$schema = $this->get('schema');
		$schema['LOGOTIP'] = 'file';
		$this->set('schema', $schema);
	}

	public function readMany() {
		return $this->readORM();
	}

	public function readOne($id) {
		$this->registerOneItemTransformHandler();
		$this->filter = array_merge($this->filter, [
			[
				'LOGIC' => 'OR',
				['ID' => $id],
				['CODE' => $id],
			]
		]);

		// Get only one item
		$this->navParams = ['nPageSize' => 1];

		$results = $this->readMany();

		if (!count($results)) {
			throw new NotFoundHttpException();
		}

		return $results;
	}
}
