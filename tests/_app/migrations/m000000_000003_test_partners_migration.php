<?php
declare(strict_types = 1);

use yii\db\Migration;

/**
 * Class m000000_000000_test_partners_migration
 */
class m000000_000003_test_partners_migration extends Migration {

	/**
	 * {@inheritdoc}
	 */
	public function safeUp() {
		$this->createTable('partners', [
			'id' => $this->primaryKey(),
			'name' => $this->string(255)->notNull()->comment('Имя партнёра'),
		]);
	}

	/**
	 * {@inheritdoc}
	 */
	public function safeDown() {
		$this->dropTable('partners');
	}

}
