<?php
declare(strict_types = 1);
use yii\db\Migration;

/**
 * Class create_users_options_table
 */
class create_users_options_table extends Migration {
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
