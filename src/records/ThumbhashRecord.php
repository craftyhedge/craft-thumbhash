<?php

namespace craftyhedge\craftthumbhash\records;

use craft\db\ActiveRecord;
use craftyhedge\craftthumbhash\db\Table;

/**
 * @property int $id
 * @property int $assetId
 * @property string|null $hash
 * @property string|null $dataUrl
 */
class ThumbhashRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::THUMBHASHES;
    }
}
