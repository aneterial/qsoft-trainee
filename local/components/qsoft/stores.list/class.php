<?php
if (! defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {die();}

use Bitrix\Main\Loader;

class QsoftStoresComponent extends CBitrixComponent
{
    public function onPrepareComponentParams(array $arParams) : array
    {
        $arParams['IBLOCK_ID'] = (! empty($arParams['IBLOCK_ID'])) ? (int) $arParams['IBLOCK_ID'] : 4;
        $arParams['CACHE_TIME'] = (! empty($arParams['CACHE_TIME'])) ? (int) $arParams['CACHE_TIME'] : 3600;
        $arParams['CACHE_TIME'] = ($arParams['CACHE_TIME'] > 0) ? $arParams['CACHE_TIME'] : 3600;

        $arParams['DETAILS_URL'] = (! empty($arParams['DETAILS_URL'])) ? htmlspecialcharsbx(trim($arParams['DETAILS_URL'])) : '';
        $arParams['SORT_BY'] = (! empty($arParams['SORT_BY'])) ? $arParams['SORT_BY'] : 'RAND';
        $arParams['SORT_ORDER'] = (! empty($arParams['SORT_ORDER'])) ? $arParams['SORT_ORDER'] : 'DESC';
        $arParams['SHOW_MAP'] = (isset($arParams['SHOW_MAP']) && $arParams['SHOW_MAP'] === 'Y');

        if ($arParams['ELEMENT_LIMIT'] = ! (isset($arParams['ELEMENT_LIMIT']) && $arParams['ELEMENT_LIMIT'] === 'N')) {
            $arParams['ELEMENT_COUNT'] = (! empty($arParams['ELEMENT_COUNT'])) ? (int) $arParams['ELEMENT_COUNT'] : 2;
            $arParams['ELEMENT_COUNT'] = ($arParams['ELEMENT_COUNT'] > 0) ? $arParams['ELEMENT_COUNT'] : 2;
        }

        return $arParams;
    }

    public function executeComponent()
    {
        $showButt = $this->getComponentButtons();

        if ($this->startResultCache(false, $showButt)) {
            if (! Loader::includeModule('iblock')) {
                $this->abortResultCache();
                return;
            }

            $this->arResult = $this->getDBResponse($this->getDBParams());

            if (! empty($this->arResult['ITEMS'])) {

                $this->arResult = $this->getElementsImage();
                $this->arResult = $this->getElementsButtons($showButt);
                $this->arResult = $this->getMapSettings();

                $this->setResultCacheKeys(['MAP_SETTINGS']);
                $this->IncludeComponentTemplate();
            }
        }
    }

    protected function getComponentButtons() : bool
    {
        global $APPLICATION;

        if ($show = $APPLICATION->GetShowIncludeAreas()) {
            if (Loader::includeModule('iblock')) {
                $componentButtons = \CIBlock::GetPanelButtons($this->arParams['IBLOCK_ID'], 0, 0, $this->getButtonsOptions());
                $this->addIncludeAreaIcons(\CIBlock::GetComponentMenu($APPLICATION->GetPublicShowMode(), $componentButtons));
            }
        }
        return $show;
    }

    protected function getElementsButtons(bool $mod) : array
    {
        if ($mod) {
            foreach ($this->arResult['ITEMS'] as $id => &$bottItem) {
                $elementButtons = \CIBlock::GetPanelButtons($arParams['IBLOCK_ID'], $id, 0, $this->getButtonsOptions());
                $bottItem['EDIT_LINK'] = $elementButtons['edit']['edit_element']['ACTION_URL'];
                $bottItem['DELETE_LINK'] = $elementButtons['edit']['delete_element']['ACTION_URL'];
            }
        }

        return $this->arResult;
    }

    protected function getButtonsOptions(bool $sectionButtons = false, bool $sessID = false, bool $reset = false) : array
    {
        static $options = null;

        if ($reset || $options === null) {
            $options = ['SECTION_BUTTONS' => $sectionButtons, 'SESSID' => $sessID];
            return $options;
        }
        return $options;
    }

    protected function getDBResponse(array $paramsDB) : array
    {
        if ($requestDB = \CIBlockElement::GetList($paramsDB['order'], $paramsDB['filter'], $paramsDB['groupBy'], $paramsDB['navParams'], $paramsDB['selectFields'])) {
            $responseDB = [];
            while ($responseDB = $requestDB->GetNext()) {
                $this->arResult['ITEMS'][$responseDB['ID']] = $responseDB;

                $this->arResult['ITEMS'][$responseDB['ID']]['EDIT_LINK'] = '';
                $this->arResult['ITEMS'][$responseDB['ID']]['DELETE_LINK'] = '';

                if (isset($responseDB['PREVIEW_PICTURE'])) {
                    $this->arResult['IMG_FILTER'][] = $responseDB['PREVIEW_PICTURE'];
                }
            }
        }

        return $this->arResult;
    }

    protected function getDBParams() : array
    {
        $paramsDB = [];

        $paramsDB['order'] = [$this->arParams['SORT_BY'] => $this->arParams['SORT_ORDER']];
        $paramsDB['filter'] = [
            'IBLOCK_ID' => $this->arParams['IBLOCK_ID'],
            'ACTIVE' => 'Y',
        ];
        $paramsDB['groupBy'] = false;
        $paramsDB['navParams'] = $this->arParams['ELEMENT_LIMIT'] ? ['nTopCount' => $this->arParams['ELEMENT_COUNT']] : false;
        $paramsDB['selectFields'] = [
            'IBLOCK_ID',
            'ID',
            'NAME',
            'PREVIEW_PICTURE',
            'DETAIL_PAGE_URL',
            'PROPERTY_WORK_HOURS',
            'PROPERTY_PHONE',
            'PROPERTY_ADDRESS',
        ];
        if ($this->arParams['SHOW_MAP']) {
            $paramsDB['selectFields'][] = 'PROPERTY_MAP';
        }

        return $paramsDB;
    }

    protected function getElementsImage() : array
    {
        if (! empty($this->arResult['IMG_FILTER'])) {
            if ($requestFilesDB = \CFile::GetList([], ['MODULE_ID' => 'iblock', '@ID' => $this->arResult['IMG_FILTER']])) {
                $imagesSRC = [];
                while ($responseFilesDB = $requestFilesDB->GetNext()) {
                    $imagesSRC[$responseFilesDB['~ID']] = \CFile::GetFileSRC($responseFilesDB);
                }
            }
        }
        foreach ($this->arResult['ITEMS'] as &$imgElement) {
            $imgElement['PREVIEW_PICTURE'] = $imagesSRC[$imgElement['PREVIEW_PICTURE']] ?? NO_IMAGE_PATH;
        }

        return $this->arResult;
    }

    protected function getMapSettings() : array
    {
        if ($this->arParams['SHOW_MAP']) {
            $settings = [];
            $arPoints = [];
            foreach ($this->arResult['ITEMS'] as $item) {
                if (! empty($item['PROPERTY_MAP_VALUE'])) {
                   list($lat, $lon) = explode(',', $item['PROPERTY_MAP_VALUE']);
                    $arPoints['lat'][] = (float) $lat;
                    $arPoints['lon'][] = (float) $lon;
                    $settings['PLACEMARKS'][] = ['LAT' => $lat, 'LON' => $lon, 'TEXT' => $item['PROPERTY_ADDRESS_VALUE']];
                }
            }
            if (! empty($arPoints)) {
                $settings['yandex_lat'] = (min($arPoints['lat']) + max($arPoints['lat']))/ 2;
                $settings['yandex_lon'] = (min($arPoints['lon']) + max($arPoints['lon']))/ 2;
                $settings['yandex_scale'] = $this->getMapScale($arPoints['lat'], $arPoints['lon']);
            } else {
                $settings['yandex_lat'] = 55.75;
                $settings['yandex_lon'] = 37.62;
                $settings['yandex_scale'] = 11;
            }

            $this->arResult['MAP_SETTINGS'] = serialize($settings);
        }

        return $this->arResult;
    }

    // Возвращает скалирование карты в зависимости от максимальной удаленности точек на карте
    protected function getMapScale(array $arLat, array $arLon) : int
    {
        // Собираем гипотетические точки  с максимально удаленными координатами, переводим в радианы
        $pointA = ['lat' => deg2rad(min($arLat)), 'lon' => deg2rad(min($arLon))];
        $pointB = ['lat' => deg2rad(max($arLat)), 'lon' => deg2rad(max($arLon))];

        // Рассчитываем расстояние между точками в км
        $distance = asin(sqrt(pow(sin(($pointB['lat'] - $pointA['lat']) / 2), 2) + cos($pointA['lat']) * cos($pointB['lat']) * pow(sin(($pointB['lon'] - $pointA['lon']) / 2), 2))) * 2 * 6367444 / 1000;

        // Подбираем скалирование карты
        $scale = 13;
        $i = 0;
        do {
            $mapScale = 10 * pow(2, $i);
            $i++;
            if (--$scale <= 2) {
                break;
            }
        } while ($mapScale < $distance);

        return $scale;
    }

}
