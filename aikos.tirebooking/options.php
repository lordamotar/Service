<?php
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\HttpApplication;
use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;

$module_id = 'aikos.tirebooking';
Loc::loadMessages(__FILE__);

$request = HttpApplication::getInstance()->getContext()->getRequest();

if (!Loader::includeModule('iblock')) {
    echo 'Модуль Инфоблоков не установлен!';
    return;
}

// Получение списка инфоблоков для выпадающего списка
$arIblocks = [];
$res = CIBlock::GetList(['NAME' => 'ASC'], ['ACTIVE' => 'Y']);
while ($ib = $res->Fetch()) {
    $arIblocks[$ib['ID']] = '[' . $ib['ID'] . '] ' . $ib['NAME'] . ' (' . $ib['IBLOCK_TYPE_ID'] . ')';
}

$aTabs = array(
    array(
        'DIV' => 'edit1',
        'TAB' => 'Настройки модуля',
        'ICON' => 'aikos_tirebooking_settings',
        'TITLE' => 'Настройки привязки инфоблоков шиномонтажа',
    ),
    array(
        'DIV' => 'edit2',
        'TAB' => 'Права доступа',
        'ICON' => 'aikos_tirebooking_settings',
        'TITLE' => 'Права доступа к модулю',
    ),
);

$tabControl = new CAdminTabControl('tabControl', $aTabs);

if ($request->isPost() && check_bitrix_sessid()) {
    if ($request->getPost('Update')) {
        Option::set($module_id, 'IBLOCK_BOOKING_ID', (int)$request->getPost('IBLOCK_BOOKING_ID'));
        Option::set($module_id, 'IBLOCK_CENTER_ID', (int)$request->getPost('IBLOCK_CENTER_ID'));
        Option::set($module_id, 'IBLOCK_SERVICE_ID', (int)$request->getPost('IBLOCK_SERVICE_ID'));
        Option::set($module_id, 'IBLOCK_POST_ID', (int)$request->getPost('IBLOCK_POST_ID'));

        ob_start();
        $Update = $request->getPost('Update');
        require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/admin/group_rights.php");
        ob_end_clean();

        CAdminMessage::ShowNote('Настройки успешно сохранены');
    }
}

$currentBookingIb = Option::get($module_id, 'IBLOCK_BOOKING_ID', 80);
$currentCenterIb = Option::get($module_id, 'IBLOCK_CENTER_ID', 38);
$currentServiceIb = Option::get($module_id, 'IBLOCK_SERVICE_ID', 78);
$currentPostIb = Option::get($module_id, 'IBLOCK_POST_ID', 77);

$tabControl->Begin();
?>
<form method="post" action="<?=$APPLICATION->GetCurPage()?>?mid=<?=urlencode($module_id)?>&amp;lang=<?=LANGUAGE_ID?>">
    <?=bitrix_sessid_post();?>
    <?php $tabControl->BeginNextTab(); ?>
    <tr>
        <td width="40%" class="adm-detail-content-cell-l">
            <label for="IBLOCK_BOOKING_ID">Инфоблок записей шиномонтажа:</label>
        </td>
        <td width="60%" class="adm-detail-content-cell-r">
            <select name="IBLOCK_BOOKING_ID" id="IBLOCK_BOOKING_ID">
                <?php foreach ($arIblocks as $id => $name): ?>
                    <option value="<?=$id?>" <?=$id == $currentBookingIb ? 'selected' : ''?>><?=$name?></option>
                <?php endforeach; ?>
            </select>
        </td>
    </tr>
    <tr>
        <td width="40%" class="adm-detail-content-cell-l">
            <label for="IBLOCK_CENTER_ID">Инфоблок шинных центров:</label>
        </td>
        <td width="60%" class="adm-detail-content-cell-r">
            <select name="IBLOCK_CENTER_ID" id="IBLOCK_CENTER_ID">
                <?php foreach ($arIblocks as $id => $name): ?>
                    <option value="<?=$id?>" <?=$id == $currentCenterIb ? 'selected' : ''?>><?=$name?></option>
                <?php endforeach; ?>
            </select>
        </td>
    </tr>
    <tr>
        <td width="40%" class="adm-detail-content-cell-l">
            <label for="IBLOCK_SERVICE_ID">Инфоблок услуг:</label>
        </td>
        <td width="60%" class="adm-detail-content-cell-r">
            <select name="IBLOCK_SERVICE_ID" id="IBLOCK_SERVICE_ID">
                <?php foreach ($arIblocks as $id => $name): ?>
                    <option value="<?=$id?>" <?=$id == $currentServiceIb ? 'selected' : ''?>><?=$name?></option>
                <?php endforeach; ?>
            </select>
        </td>
    </tr>
    <tr>
        <td width="40%" class="adm-detail-content-cell-l">
            <label for="IBLOCK_POST_ID">Инфоблок постов:</label>
        </td>
        <td width="60%" class="adm-detail-content-cell-r">
            <select name="IBLOCK_POST_ID" id="IBLOCK_POST_ID">
                <?php foreach ($arIblocks as $id => $name): ?>
                    <option value="<?=$id?>" <?=$id == $currentPostIb ? 'selected' : ''?>><?=$name?></option>
                <?php endforeach; ?>
            </select>
        </td>
    </tr>
    <?php $tabControl->BeginNextTab(); ?>
    <?php require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/admin/group_rights.php"); ?>
    <?php $tabControl->Buttons(); ?>
    <input type="submit" name="Update" value="Сохранить настройки" title="Сохранить изменения" class="adm-btn-save">
    <?php $tabControl->End(); ?>
</form>
