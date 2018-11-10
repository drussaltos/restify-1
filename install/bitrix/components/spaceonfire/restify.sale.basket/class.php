<?php

namespace spaceonfire\Restify;

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use spaceonfire\Restify\Executors\SaleBasketRest;

if (!Loader::includeModule('spaceonfire.restify')) return false;

Loc::loadLanguageFile(__FILE__);

class RestifySaleBasketComponent extends RouterComponent {
	public function executeComponent() {
		$executor = new SaleBasketRest($this->arParams);
		$this->setExecutor($executor);
		$this->cors();
		$this->route('GET /', [$this, 'read']);
		$this->route('POST /', [$this, 'update']);
		$this->route('POST /@id', [$this, 'update']);
		$this->route('DELETE /@id', [$this, 'delete']);
		$this->start();
	}
}
