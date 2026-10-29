<?php

declare(strict_types=1);

namespace Roman140176\Redirects\Model;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\EntityError;
use Bitrix\Main\ORM\Event;
use Bitrix\Main\ORM\EventResult;
use Bitrix\Main\ORM\Fields\BooleanField;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\Validators\RegExpValidator;
use Bitrix\Main\SiteDomainTable;
use Bitrix\Main\SiteTable;
use Bitrix\Main\Type\DateTime;
use Roman140176\Redirects\Rule\Chain;
use Roman140176\Redirects\Rule\ChainChecker;
use Roman140176\Redirects\Rule\Url;
use Roman140176\Redirects\RuleCache;

/**
 * Правило редиректа: откуда, куда и с каким кодом. Петли не сохраняются, после записи кеш правил сбрасывается
 */
class RuleTable extends DataManager
{
    public const STATUSES = [301, 302];

    // в пакетном режиме дубли и петли проверяет импорт сам, по списку в памяти, а кеш сбрасывается один раз в конце
    private static bool $bulk = false;

    public static function getTableName(): string
    {
        return 'roman140176_redirects_rule';
    }

    public static function getMap(): array
    {
        return [
            (new IntegerField('ID'))
                ->configurePrimary()
                ->configureAutocomplete(),
            (new StringField('SITE_ID'))
                ->configureTitle(Loc::getMessage('ROMAN140176_REDIRECTS_RULE_SITE_ID'))
                ->configureRequired()
                ->addValidator(new RegExpValidator('/^[a-z0-9]{2}$/i')),
            (new BooleanField('ACTIVE'))
                ->configureValues('N', 'Y')
                ->configureDefaultValue(true),
            (new StringField('SOURCE'))
                ->configureTitle(Loc::getMessage('ROMAN140176_REDIRECTS_RULE_SOURCE'))
                ->configureRequired()
                ->addValidator(static fn ($value) => Url::isSource((string)$value) ?: Loc::getMessage('ROMAN140176_REDIRECTS_RULE_SOURCE_FORMAT')),
            // SOURCE для сравнения, его ставит onBefore*, руками не задается
            (new StringField('SOURCE_KEY')),
            (new StringField('TARGET'))
                ->configureTitle(Loc::getMessage('ROMAN140176_REDIRECTS_RULE_TARGET'))
                ->configureRequired()
                ->addValidator(static fn ($value) => Url::isTarget((string)$value) ?: Loc::getMessage('ROMAN140176_REDIRECTS_RULE_TARGET_FORMAT')),
            (new IntegerField('STATUS'))
                ->configureDefaultValue(301)
                ->addValidator(static fn ($value) => in_array((int)$value, self::STATUSES, true) ?: Loc::getMessage('ROMAN140176_REDIRECTS_RULE_STATUS_FORMAT')),
            (new IntegerField('HITS'))
                ->configureDefaultValue(0),
            (new DatetimeField('LAST_HIT_AT'))
                ->configureNullable(),
            (new DatetimeField('CREATED_AT'))
                ->configureDefaultValue(static fn (): DateTime => new DateTime()),
        ];
    }

    public static function onBeforeAdd(Event $event): EventResult
    {
        return self::prepare(0, $event->getParameter('fields'));
    }

    public static function onBeforeUpdate(Event $event): EventResult
    {
        $id = (int)$event->getParameter('primary')['ID'];
        $fields = $event->getParameter('fields');
        // в событие приходят только измененные поля, остальные берем из базы
        $current = self::getByPrimary($id, ['select' => ['SITE_ID', 'ACTIVE', 'SOURCE', 'TARGET', 'STATUS']])->fetch() ?: [];

        return self::prepare($id, $fields + $current);
    }

    public static function onAfterAdd(Event $event): void
    {
        if (!self::$bulk) {
            RuleCache::clear();
        }
    }

    public static function onAfterUpdate(Event $event): void
    {
        if (!self::$bulk) {
            RuleCache::clear();
        }
    }

    public static function onAfterDelete(Event $event): void
    {
        if (!self::$bulk) {
            RuleCache::clear();
        }
    }

    /**
     * @return list<array{ID: int, SOURCE: string, TARGET: string, STATUS: int}>
     */
    public static function activeRules(string $siteId): array
    {
        $rows = self::query()
            ->setSelect(['ID', 'SOURCE', 'TARGET', 'STATUS'])
            ->where('SITE_ID', $siteId)
            ->where('ACTIVE', true)
            ->fetchAll();

        return array_map(static fn (array $row): array => [
            'ID' => (int)$row['ID'],
            'SOURCE' => (string)$row['SOURCE'],
            'TARGET' => (string)$row['TARGET'],
            'STATUS' => (int)$row['STATUS'],
        ], $rows);
    }

    // много правил подряд (импорт): без проверок на каждой строке, иначе тысячи строк идут минуты
    public static function bulk(callable $callback): mixed
    {
        self::$bulk = true;
        try {
            return $callback();
        } finally {
            self::$bulk = false;
            RuleCache::clear();
        }
    }

    /**
     * @return string[] домены сайта: полный адрес на них в "Куда" это тот же сайт
     */
    public static function siteHosts(string $siteId): array
    {
        $hosts = array_column(SiteDomainTable::getList(['select' => ['DOMAIN'], 'filter' => ['=LID' => $siteId]])->fetchAll(), 'DOMAIN');
        $serverName = (string)(SiteTable::getList(['select' => ['SERVER_NAME'], 'filter' => ['=LID' => $siteId]])->fetch()['SERVER_NAME'] ?? '');
        if ($serverName !== '') {
            $hosts[] = $serverName;
        }
        // у сайта домен не задан: берем общий из настроек главного модуля
        if ($hosts === []) {
            $hosts[] = Option::get('main', 'server_name');
        }

        return array_values(array_filter($hosts));
    }

    public static function duplicateMessage(string $source): string
    {
        return (string)Loc::getMessage('ROMAN140176_REDIRECTS_RULE_DUPLICATE', ['#SOURCE#' => $source]);
    }

    public static function loopMessage(Chain $chain): string
    {
        return (string)Loc::getMessage('ROMAN140176_REDIRECTS_RULE_LOOP', ['#CHAIN#' => implode(' > ', $chain->urls)]);
    }

    // счетчик пишем мимо ORM: события сбросили бы кеш правил на каждом редиректе
    public static function registerHit(int $id): void
    {
        $connection = Application::getConnection();
        $connection->queryExecute(sprintf(
            'UPDATE %s SET HITS = HITS + 1, LAST_HIT_AT = %s WHERE ID = %d',
            self::getTableName(),
            $connection->getSqlHelper()->convertToDbDateTime(new DateTime()),
            $id
        ));
    }

    // ключ для сравнения, дубли и петли. Если поля кривые, их и так отклонят валидаторы
    private static function prepare(int $id, array $fields): EventResult
    {
        $result = new EventResult();
        $source = (string)($fields['SOURCE'] ?? '');
        $target = (string)($fields['TARGET'] ?? '');
        $siteId = (string)($fields['SITE_ID'] ?? '');
        if (!Url::isSource($source) || !Url::isTarget($target) || $siteId === '') {
            return $result;
        }

        $key = Url::key($source);
        $result->modifyFields(['SOURCE_KEY' => $key]);
        if (self::$bulk) {
            return $result;
        }

        $duplicate = self::query()
            ->setSelect(['ID'])
            ->where('SITE_ID', $siteId)
            ->where('SOURCE_KEY', $key)
            ->where('ID', '!=', $id)
            ->fetch();
        if ($duplicate) {
            $result->addError(new EntityError(self::duplicateMessage($source)));

            return $result;
        }

        // выключенное правило никуда не ведет, петли от него нет
        $active = $fields['ACTIVE'] ?? true;
        if ($active === true || $active === 'Y') {
            $chain = ChainChecker::check(self::activeRules($siteId), [
                'ID' => $id,
                'SOURCE' => $source,
                'TARGET' => $target,
                'STATUS' => (int)($fields['STATUS'] ?? 301),
            ], self::siteHosts($siteId));
            if ($chain->loop) {
                $result->addError(new EntityError(self::loopMessage($chain)));
            }
        }

        return $result;
    }
}
