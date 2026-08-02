<?php
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Config\Option;

Loc::loadMessages(__FILE__);

class aikos_tirebooking extends CModule
{
    public $MODULE_ID = "aikos.tirebooking";
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME;
    public $MODULE_DESCRIPTION;

    public function __construct()
    {
        $arModuleVersion = array();
        include(__DIR__ . "/version.php");

        $this->MODULE_VERSION = $arModuleVersion["VERSION"];
        $this->MODULE_VERSION_DATE = $arModuleVersion["VERSION_DATE"];
        $this->MODULE_NAME = "Записи шиномонтажа и отслеживание";
        $this->MODULE_DESCRIPTION = "Модуль управления записями на шиномонтаж и интерактивный гаджет отслеживания для рабочего стола Битрикс24.";
        $this->PARTNER_NAME = "AIKOS";
        $this->PARTNER_URI = "https://aikos.kz";
    }

    public function DoInstall()
    {
        global $APPLICATION;
        ModuleManager::registerModule($this->MODULE_ID);
        $this->InstallFiles();
        $APPLICATION->IncludeAdminFile("Установка модуля " . $this->MODULE_NAME, __DIR__ . "/step1.php");
    }

    public function DoUninstall()
    {
        global $APPLICATION;
        $this->UnInstallFiles();
        ModuleManager::unRegisterModule($this->MODULE_ID);
    }

    public function InstallFiles()
    {
        CopyDirFiles(__DIR__ . "/gadgets", $_SERVER["DOCUMENT_ROOT"] . "/bitrix/gadgets", true, true);
        CopyDirFiles(__DIR__ . "/../admin", $_SERVER["DOCUMENT_ROOT"] . "/bitrix/admin", true, true);
        return true;
    }

    public function UnInstallFiles()
    {
        DeleteDirFilesEx("/bitrix/gadgets/aikos/tire_booking_tracker");
        DeleteDirFiles(__DIR__ . "/../admin", $_SERVER["DOCUMENT_ROOT"] . "/bitrix/admin");
        return true;
    }
}
