<?php
declare(strict_types = 1);
use yii\db\Migration;

/**
 * Class m000000_000000_create_users_options_table
 */
class m000000_000000_create_options_table extends Migration {
	private const TABLE_NAME = 'sys_options';

	/**
	 * {@inheritdoc}
	 */
	public function safeUp() {
		$this->createTable(self::TABLE_NAME, [
			'id' => $this->primaryKey(),
			'option' => $this->string(256)->notNull()->comment('Option name'),
			'value' => $this->binary()->null()->comment('Serialized option value')
		]);

		$this->createIndex(self::TABLE_NAME.'_option', self::TABLE_NAME, ['option'], true);
	}

	/**
	 * {@inheritdoc}
	 */
	public function safeDown() {
		$this->dropTable(self::TABLE_NAME);
	}

}
