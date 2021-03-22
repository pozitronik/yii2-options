<?php
declare(strict_types = 1);
use yii\db\Migration;

/**
 * Class m000000_000000_create_users_options_table
 */
class m000000_000000_create_options_table extends Migration {
	/**
	 * {@inheritdoc}
	 */
	public function safeUp() {
		$this->createTable('sys_options', [
			'id' => $this->primaryKey(),
			'option' => $this->string(256)->notNull()->comment('Option name'),
			'value' => $this->binary()->null()->comment('Serialized option value')
		]);

		$this->createIndex('option', 'sys_options', ['option'], true);
	}

	/**
	 * {@inheritdoc}
	 */
	public function safeDown() {
		$this->dropTable('sys_options');
	}

}
