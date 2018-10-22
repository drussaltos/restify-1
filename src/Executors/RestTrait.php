<?php

namespace goldencode\Bitrix\Restify\Executors;

use CSite;
use Bitrix\Main\Event;
use Bitrix\Main\EventManager;
use Bitrix\Main\Application;
use Bitrix\Main\Context;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Emonkak\HttpException\InternalServerErrorHttpException;
use Flight;
use flight\net\Request;
use goldencode\Helpers\Bitrix\Tools;
use ReflectionObject;
use ReflectionProperty;

trait RestTrait {
	/**
	 * @var array Bitrix query order
	 */
	public $order = ['SORT' => 'ASC'];

	/**
	 * @var array Bitrix query filter
	 */
	public $filter = ['ACTIVE' => 'Y'];

	/**
	 * @var array Bitrix query nav params
	 */
	public $navParams = ['nPageSize' => 25];

	/**
	 * @var array Bitrix query select fields
	 */
	public $select = ['*'];

	/**
	 * @var array Parsed request body
	 */
	public $body = [];

	/**
	 * @var Context Bitrix Application Context
	 */
	private $context;

	/**
	 * @var Request Bitrix Application Context
	 */
	private $flightRequest;

	/**
	 * @var array Entity schema
	 */
	private $schema = [];

	/**
	 * @var array Map formatters to schema field types
	 */
	private $formatters = [
		'goldencode\Bitrix\Restify\Formatters\DateFormatter' => 'date',
		'goldencode\Bitrix\Restify\Formatters\FileFormatter' => 'file',
	];

	/**
	 * @var \CBitrixComponent bitrix component instance
	 */
	private $component;

	/**
	 * Get property value
	 * @param string $name
	 * @return mixed
	 */
	public function get($name) {
		return $this->{$name};
	}

	/**
	 * Set property value
	 * @param string $name
	 * @param mixed $value
	 */
	public function set($name, $value): void {
		$this->{$name} = $value;
	}

	/**
	 * Load bitrix modules or response with error
	 * @param array | string $modules
	 * @param bool $throw
	 * @return bool
	 * @throws \Bitrix\Main\LoaderException
	 */
	private function loadModules($modules, $throw = true) {
		if (!is_array($modules)) {
			$modules = [$modules];
		}

		foreach ($modules as $module) {
			$loaded = Loader::includeModule($module);
			if (!$loaded) {
				if ($throw) {
					throw new InternalServerErrorHttpException(Loc::getMessage('MODULE_NOT_INSTALLED', [
						'#MODULE#' => 'iblock',
					]));
				} else {
					return false;
				}
			}
		}

		return true;
	}

	public function prepareQuery() {
		global $DB;
		$this->context = Application::getInstance()->getContext();
		$this->flightRequest = Flight::request();

		foreach (['order', 'filter', 'select', 'navParams'] as $field) {
			$value = $this->context->getRequest()->get($field);

			if (!$value) continue;

			if (json_decode($value, true)) {
				$value = json_decode($value, true);
			}

			if (!is_array($value)) {
				$value = [$value];
			}

			switch ($field) {
				case 'filter':
					// Convert filter by date to site format
					$dateFields = array_keys(array_filter($this->schema, function($type) {
						return in_array($type, ['date', 'datetime']);
					}));

					foreach ($dateFields as $dateField) {
						$keys = array_filter(array_keys($value), function($key) use ($dateField) {
							return strpos($key, $dateField) !== false;
						});
						foreach ($keys as $key) {
							if ($value[$key]) {
								$value[$key] = date(
									$DB->DateFormatToPHP(CSite::GetDateFormat()),
									strtotime($value[$key])
								);
							}
						}
					}
					break;

				case 'navParams':
					if (empty($value['nPageSize'])) {
						$value['nPageSize'] = $this->navParams['nPageSize'];
					}
					break;
			}

			$this->{$field} = $value;
		}

		$this->body = $this->flightRequest->data->getData();
	}

	/**
	 * Generate success message
	 * @param mixed $message
	 * @return array success message
	 */
	public function success($message) {
		return [
			'result' => 'ok',
			'message' => $message
		];
	}

	private function setSelectFieldsFromEntityClass(): void {
		if (property_exists($this, 'entity') && is_callable([$this->entity, 'getMap'])) {
			$this->select = array_keys(call_user_func([$this->entity, 'getMap']));
		}
	}

	private function setPropertiesFromArray(array $options): void {
		// Set properties from $options arg. Do not touch private props
		$reflection = new ReflectionObject($this);
		$properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED);
		foreach ($properties as $property) {
			$prop = $property->getName();
			if (!empty($options[$prop])) {
				$this->{$prop} = $options[$prop];
			}
		}
	}

	private function registerBasicTransformHandler() {
		global $goldenCodeRestify;
		// Register transform
		EventManager::getInstance()->addEventHandler(
			$goldenCodeRestify->getId(),
			'transform',
			[$this, 'basicTransformActions']
		);
	}

	public function basicTransformActions(Event $event) {
		$params = $event->getParameters();
		foreach ($params['result'] as $key => $item) {
			$item = Tools::removeTildaKeys($item);
			$item = self::decodeSpecialChars($item);
			$item = $this->runFormatters($item);
			$params['result'][$key] = $item;
		}
	}

	/**
	 * Decode html entities and special chars in content fields
	 * @param array $item
	 * @return array
	 */
	private static function decodeSpecialChars($item) {
		$contentFields = ['NAME', 'PREVIEW_TEXT', 'DETAIL_TEXT'];
		foreach ($contentFields as $field) {
			if ($item[$field]) {
				$item[$field] = html_entity_decode($item[$field], ENT_QUOTES | ENT_HTML5);
			}
		}
		return $item;
	}

	private function runFormatters($item) {
		foreach ($this->formatters as $formatter => $type) {
			$fields = array_keys(array_filter($this->schema, function ($fieldType) use ($type) {
				return strpos($fieldType, $type) !== false;
			}));

			// Add suffix to properties fields
			$fields = array_map(function ($field) {
				return strpos($field, 'PROPERTY') !== false ? $field . '_VALUE' : $field;
			}, $fields);

			foreach ($fields as $field) {
				if (!empty($item[$field])) {
					if (!is_array($item[$field])) {
						$item[$field] = call_user_func_array([$formatter, 'format'], [$item[$field]]);
					} else {
						$item[$field] = array_map(function ($val) use ($formatter) {
							return call_user_func_array([$formatter, 'format'], [$val]);
						}, $item[$field]);
					}
				}
			}
		}

		return $item;
	}

	private function registerOneItemTransformHandler() {
		global $goldenCodeRestify;
		// Register transform
		EventManager::getInstance()->addEventHandler(
			$goldenCodeRestify->getId(),
			'transform',
			[$this, 'popOneItemTransformAction']
		);
	}

	public function popOneItemTransformAction(Event $event) {
		$params = $event->getParameters();
		$params['result'] = array_pop($params['result']);
	}
}