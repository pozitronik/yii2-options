<?php
declare(strict_types = 1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Tests\Support\UnitTester;
use Yii;
use yii\base\Application;

/**
 *
 */
class MainTest extends Unit {

	protected UnitTester $tester;

	/**
	 * @Override
	 */
	protected function _before():void {
	}

	/**
	 * @return void
	 */
	public function testSomeFeature():void {
		$this->tester->assertInstanceOf(Application::class, Yii::$app);
	}
}
