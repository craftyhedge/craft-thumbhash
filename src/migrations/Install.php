<?php

namespace craftyhedge\craftthumbhash\migrations;

use craft\db\Migration;
use craftyhedge\craftthumbhash\db\Table;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTable(Table::THUMBHASHES, [
            'id' => $this->primaryKey(),
            'assetId' => $this->integer()->notNull(),
            'hash' => $this->string(255)->null(),
            'dataUrl' => $this->text()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, Table::THUMBHASHES, ['assetId'], true);

        $this->addForeignKey(
            null,
            Table::THUMBHASHES,
            'assetId',
            '{{%elements}}',
            'id',
            'CASCADE',
            null,
        );

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::THUMBHASHES);

        return true;
    }
}
