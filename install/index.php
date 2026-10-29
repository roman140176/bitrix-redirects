<?php

declare(strict_types=1);

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\EventManager;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Web\Json;
use Roman140176\Redirects\EventHandler;
use Roman140176\Redirects\Log\NotFoundLog;
use Roman140176\Redirects\RuleCache;

class roman140176_redirects extends CModule
{
    public $MODULE_ID = 'roman140176.redirects';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME;
    public $MODULE_DESCRIPTION;
    public $PARTNER_NAME;
    public $PARTNER_URI;
    public $MODULE_GROUP_RIGHTS = 'Y';

    private const MIN_MAIN_VERSION = '23.0.0';
    // таблицы по именам: пока модуль не зарегистрирован, его сущности не подключить
    private const TABLES = ['roman140176_redirects_rule', 'roman140176_redirects_404'];
    // событие ядра => метод EventHandler
    private const EVENTS = [
        'OnPageStart' => 'onPageStart',
        'OnEpilog' => 'onEpilog',
    ];
    private const CLEANUP_INTERVAL = 86400;
    // страницы админки: заглушка в /bitrix/admin => файл в admin/ модуля
    private const ADMIN_PAGES = [
        'roman140176_redirects_rules.php' => 'rule_list.php',
        'roman140176_redirects_rule_edit.php' => 'rule_edit.php',
        'roman140176_redirects_log.php' => 'log_list.php',
        'roman140176_redirects_import.php' => 'import.php',
    ];

    public function __construct()
    {
        $arModuleVersion = [];
        include __DIR__ . '/version.php';

        $this->MODULE_VERSION = $arModuleVersion['VERSION'];
        $this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];
        $this->MODULE_NAME = Loc::getMessage('ROMAN140176_REDIRECTS_MODULE_NAME');
        $this->MODULE_DESCRIPTION = Loc::getMessage('ROMAN140176_REDIRECTS_MODULE_DESCRIPTION');
        $this->PARTNER_NAME = Loc::getMessage('ROMAN140176_REDIRECTS_PARTNER_NAME');
        $this->PARTNER_URI = 'https://github.com/roman140176';
    }

    // ставится в один шаг, итог показывает штатная страница "Установленные решения"
    public function DoInstall(): bool
    {
        return $this->install();
    }

    // при удалении сначала спрашиваем, оставить ли правила и журнал (unstep1.php)
    public function DoUninstall(): bool
    {
        global $APPLICATION;

        $request = Application::getInstance()->getContext()->getRequest();
        if ((int)$request->get('step') < 2) {
            $APPLICATION->IncludeAdminFile(Loc::getMessage('ROMAN140176_REDIRECTS_UNINSTALL_TITLE'), __DIR__ . '/unstep1.php');
        }

        return $this->uninstall($request->get('keep_data') === 'Y');
    }

    public function install(): bool
    {
        global $APPLICATION;

        $problems = [];
        if (PHP_VERSION_ID < 80100) {
            $problems[] = Loc::getMessage('ROMAN140176_REDIRECTS_NEED_PHP');
        }
        if (version_compare(ModuleManager::getVersion('main'), self::MIN_MAIN_VERSION, '<')) {
            $problems[] = Loc::getMessage('ROMAN140176_REDIRECTS_NEED_MAIN', ['#MAIN#' => self::MIN_MAIN_VERSION]);
        }
        if (!is_file($this->sqlPath('install.sql'))) {
            $problems[] = Loc::getMessage('ROMAN140176_REDIRECTS_NEED_MYSQL');
        }
        if ($problems !== []) {
            $APPLICATION->ThrowException(implode('<br>', $problems));

            return false;
        }

        if (!$this->InstallDB()) {
            return false;
        }
        $this->InstallFiles();

        return true;
    }

    public function uninstall(bool $keepData): bool
    {
        $this->UnInstallFiles();

        return $this->UnInstallDB(['savedata' => $keepData]);
    }

    // таблицы, регистрация, обработчики событий, агент очистки журнала. Таблицы, оставшиеся от удаления с сохранением данных, берем как есть
    public function InstallDB(): bool
    {
        global $APPLICATION;

        $connection = Application::getConnection();
        $existing = array_values(array_filter(self::TABLES, [$connection, 'isTableExists']));
        if ($existing !== [] && count($existing) < count(self::TABLES)) {
            // осталась только часть таблиц, значит прошлая установка сломана. Поверх не ставим
            $APPLICATION->ThrowException(Loc::getMessage('ROMAN140176_REDIRECTS_TABLES_PARTIAL', [
                '#TABLES#' => implode(', ', $existing),
            ]));

            return false;
        }

        if ($existing === []) {
            $errors = $this->runSqlFile('install.sql');
            if ($errors !== []) {
                $APPLICATION->ThrowException(implode('<br>', $errors));

                return false;
            }
        }

        ModuleManager::registerModule($this->MODULE_ID);
        Loader::includeModule($this->MODULE_ID);

        // OnPageStart и OnEpilog старые события ядра, поэтому регистрация в старом формате
        $eventManager = EventManager::getInstance();
        foreach (self::EVENTS as $event => $method) {
            $eventManager->registerEventHandlerCompatible('main', $event, $this->MODULE_ID, EventHandler::class, $method);
        }
        CAgent::AddAgent('\\' . NotFoundLog::class . '::cleanup();', $this->MODULE_ID, 'N', self::CLEANUP_INTERVAL);
        $this->restoreRights();

        return true;
    }

    // savedata: оставить таблицы и настройки, чтобы потом поставить модуль обратно с теми же правилами
    public function UnInstallDB(array $params = []): bool
    {
        global $APPLICATION;

        if (Loader::includeModule($this->MODULE_ID)) {
            RuleCache::clear();
        }

        if (($params['savedata'] ?? false) === true) {
            $this->saveRights();
        } else {
            // если таблицы не удалились, регистрацию не снимаем, иначе модуль потом не переустановить
            $errors = $this->runSqlFile('uninstall.sql');
            if ($errors !== []) {
                $APPLICATION->ThrowException(implode('<br>', $errors));

                return false;
            }
            Option::delete($this->MODULE_ID);
        }

        $eventManager = EventManager::getInstance();
        foreach (self::EVENTS as $event => $method) {
            $eventManager->unRegisterEventHandler('main', $event, $this->MODULE_ID, EventHandler::class, $method);
        }
        CAgent::RemoveModuleAgents($this->MODULE_ID);
        ModuleManager::unRegisterModule($this->MODULE_ID);

        return true;
    }

    // заглушки страниц админки в /bitrix/admin
    public function InstallFiles(): bool
    {
        // модуль может лежать и в /local/modules, и в /bitrix/modules, путь берем настоящий
        $moduleDir = getLocalPath('modules/' . $this->MODULE_ID);
        foreach (self::ADMIN_PAGES as $stub => $page) {
            file_put_contents(
                Application::getDocumentRoot() . '/bitrix/admin/' . $stub,
                "<?php\nrequire \$_SERVER['DOCUMENT_ROOT'] . '" . $moduleDir . '/admin/' . $page . "';\n"
            );
        }

        return true;
    }

    public function UnInstallFiles(): bool
    {
        foreach (array_keys(self::ADMIN_PAGES) as $stub) {
            $path = Application::getDocumentRoot() . '/bitrix/admin/' . $stub;
            if (is_file($path)) {
                unlink($path);
            }
        }

        return true;
    }

    // права с вкладки "Доступ" ядро удаляет вместе с регистрацией модуля, поэтому при удалении
    // с сохранением данных запоминаем их в настройках, а при установке ставим обратно
    private function saveRights(): void
    {
        $rights = [];
        $list = CMain::GetGroupRightList(['MODULE_ID' => $this->MODULE_ID]);
        while ($row = $list->fetch()) {
            $rights[(int)$row['GROUP_ID']] = $row['G_ACCESS'];
        }
        Option::set($this->MODULE_ID, 'saved_rights', Json::encode($rights));
    }

    private function restoreRights(): void
    {
        global $APPLICATION;

        $saved = Option::get($this->MODULE_ID, 'saved_rights');
        if ($saved === '') {
            return;
        }
        foreach ((array)Json::decode($saved) as $groupId => $right) {
            $APPLICATION->SetGroupRight($this->MODULE_ID, (int)$groupId, (string)$right);
        }
        Option::delete($this->MODULE_ID, ['name' => 'saved_rights']);
    }

    /**
     * @return string[] ошибки SQL
     */
    private function runSqlFile(string $name): array
    {
        return Application::getConnection()->executeSqlBatch(file_get_contents($this->sqlPath($name)));
    }

    // схема лежит по типу базы: db/mysql/install.sql. Для другой базы файла нет, и установка откажет на проверке
    private function sqlPath(string $name): string
    {
        return __DIR__ . '/db/' . Application::getConnection()->getType() . '/' . $name;
    }
}
