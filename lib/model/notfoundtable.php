<?php

declare(strict_types=1);

namespace Roman140176\Redirects\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;

/**
 * Журнал 404: адрес, сколько раз открывали, когда и откуда пришли. Пишет его NotFoundLog
 */
class NotFoundTable extends DataManager
{
    public static function getTableName(): string
    {
        return 'roman140176_redirects_404';
    }

    public static function getMap(): array
    {
        return [
            (new IntegerField('ID'))
                ->configurePrimary()
                ->configureAutocomplete(),
            new StringField('SITE_ID'),
            // путь раскодирован, параметры как пришли
            new StringField('URL'),
            // md5 от адреса в нижнем регистре, по нему ищем строку
            new StringField('URL_HASH'),
            (new StringField('REFERER'))
                ->configureNullable(),
            new IntegerField('HITS'),
            new DatetimeField('FIRST_AT'),
            new DatetimeField('LAST_AT'),
        ];
    }
}
