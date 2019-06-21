// Событие от вебдебага вызываеся ПЕРЕД добавлением товаров и товарных предложений
Bitrix\Main\EventManager::getInstance()->addEventHandler("webdebug.import", "OnBeforeLoadObject", ["ExtensionsWebdebugHandler", "webdebugBeforeImport"]);

// Событие от вебдебага вызываеся ПОСЛЕ добавления товаров и товарных предложений
Bitrix\Main\EventManager::getInstance()->addEventHandler("webdebug.import", "OnAfterLoadObject", ["ExtensionsWebdebugHandler", "webdebugAfterImport"]);

class ExtensionsWebdebugHandler
{
  function webdebugBeforeImport(&$ObjectID, &$arObject, &$arData)
  {
    if(
      ($arData['PROFILE']['HANDLER'] == 'GIFTS' || $arData['PROFILE']['HANDLER'] == 'HAPPYGIFTS')
      && ($arObject['TYPE'] == 'E' || $arObject['TYPE'] == 'O')
    )
    {
      // Если у товара нет ни одного из свойств "Размер", "Цвет" или "Объем памяти", то ему не будем создавать ТП
      // Или, у товара есть только размер (10*14 см, 15x21 см) и пустые свойства "Цвет" и "Объем памяти"
      //$create_offer = TRUE;
      if(
        $arObject['TYPE'] == 'E' &&
        (
          (
            empty($arObject['FIELDS']['PROPERTY_145']) &&
            empty($arObject['FIELDS']['PROPERTY_126']) &&
            empty($arObject['FIELDS']['PROPERTY_224'])
          ) ||
          (
            preg_match('/^\d{2}.\d{2}\s*[а-яА-ЯЁёa-zA-Z]+$/iu', $arObject['FIELDS']['PROPERTY_145']) &&
            empty($arObject['FIELDS']['PROPERTY_126']) &&
            empty($arObject['FIELDS']['PROPERTY_224'])
          )
        )
      )
      {
        //$create_offer = FALSE;
        return;
      }

      // Проверка артикула на принадлежность к группе исключений из нормы
      $happygifts_articles = array(399879,399883,399895,399896,399900,399902,399901,161,399905,399917,25300,25301,25203,25204,399922,399919,399921);
      $hpart = FALSE;
      if($arData['PROFILE']['HANDLER'] == 'HAPPYGIFTS' && $arObject['TYPE'] == 'E')
      {
        $tempArticle = $arObject['FIELDS']['PROPERTY_135'];

        // Артикулы вида 399901.19/XXL, 399901.98/XL
        if(preg_match('/^([0-9]+.[0-9]{1})[0-9]+\/[a-zA-Z]+$/', $tempArticle, $out_01))
          if(in_array(substr($tempArticle, 0, strpos($tempArticle, ".")), $happygifts_articles))
            $hpart = $out_01[1];

        // Артикулы вида 161/74/35, 161/01/136, 161/01/132
        if(preg_match('/^([0-9]+\/[0-9a-zA-Z]{1})[0-9a-zA-Z]+\/[0-9a-zA-Z]+$/', $tempArticle, $out_02))
          if(in_array(substr($tempArticle, 0, strpos($tempArticle, "/")), $happygifts_articles))
            $hpart = $out_02[1];

        // Артикулы вида 161/F, 161/35, 161/136
        if(preg_match('/^([0-9]+\/[0-9a-zA-Z]{1})[0-9a-zA-Z]*$/', $tempArticle, $out_03))
          if(in_array(substr($tempArticle, 0, strpos($tempArticle, "/")), $happygifts_articles))
            $hpart = $out_03[1];
      }

      // Формируем фильтр для поиска товара в каталоге с таким же артикулом
      $arFilter['IBLOCK_ID'] = $arObject['IBLOCK_ID'];
      $arFilter['INCLUDE_SUBSECTIONS'] = 'Y';
      $arFilter['CHECK_PERMISSIONS'] = 'N';

      // Вычисляем артикул до точки, из строки типа '1379.60' или '613760.94/L'
      switch ($arObject['TYPE'])
      {
        case 'E':
          if($arData['PROFILE']['HANDLER'] == 'HAPPYGIFTS' && $hpart)
            $ARTICLE = $hpart;
          else
            $ARTICLE = self::trimArticle($arObject['FIELDS']['PROPERTY_135']);
          break;
        case 'O':
          $ARTICLE = self::trimArticle($arObject['FIELDS']['PROPERTY_184']);
          break;
      }
      if($arData['PROFILE']['HANDLER'] == 'GIFTS')
      {
        // Не обрабатываем артикулы вида 88U-09006, 88U-09010, F81-01213, F81-09213, Z6234, Z04125
        if((!preg_match('/^([0-9A-Z]{3}-[0-9]+)/', $ARTICLE)) or (!preg_match('/^([A-Z]{1}[0-9]{4,5})/', $ARTICLE)))
        {
          // Артикулы вида 11939280XXS, PU4090011S, 590003705XL
          if(preg_match('/(^[0-9A-Z]{8})([0-9SMLX]+)/', $ARTICLE, $arrArticle))
            $ARTICLE = $arrArticle[1];
          // Артикулы вида MKT8448blue, MKT4806grey, MKT9961white
          if(preg_match('/(^[A-Z]+[0-9]+)([a-z]+)/', $ARTICLE, $arrArticle))
            $ARTICLE = $arrArticle[1];
          // Артикулы вида 82000146TUN, 88U-08005 обрежем до 5-ти символов (общий корень)
          if(strlen($ARTICLE) >= 8)
            $ARTICLE = mb_strimwidth($ARTICLE, 0, 5);
        }
      }

      if(isset($ARTICLE) && !empty($ARTICLE))
      {
        // Получим код поля "Привязка элементов для ..."
        if(is_array($arObject['FILTER']))
          foreach ($arObject['FILTER'] as $code => $value)
            $arFilter = array(str_replace('=', '!', $code) => false);
            //$arFilter[str_replace('=', '!', $code)] = 0;

        $arFilter['PROPERTY_135'] = $ARTICLE;

        // Делаем запрос в БД, и проверяем - существует ли товар с таким же артикулом?
        $arItem = CIBlockElement::GetList(array(), $arFilter, false, false, array('ID', 'IBLOCK_ID', 'IBLOCK_SECTION_ID', 'NAME', 'PROPERTY_272', 'PROPERTY_231', 'PROPERTY_EXT_GIFTS', 'PROPERTY_EXT_HAPPYGIFTS'))->GetNext(false,false);
        if($arItem)
        {
          // В БД уже есть товар с заданным артикулом

          if($arObject['TYPE'] == 'E' /*&& $create_offer*/) // Загрузка элементов
          {
            if($arData['PROFILE']['HANDLER'] == 'GIFTS')
            {
              if ((strlen($arObject['FIELDS']['PROPERTY_135']) >  strlen($ARTICLE)) && (!$existsOffer = self::getOfferId($arObject['EXTERNAL_ID'])))
              {
                // Обновление общего товара, где собраны Торговые пред.
                if($arObject['EXTERNAL_ID'] == $arItem['PROPERTY_272_VALUE'])
                  $PRODUCT_ID = self::addOrUpdateProduct($arObject, $ARTICLE, $arData['PROFILE']['HANDLER'], $arItem);

                // Изменим Товар на Торговое предложени
                self::convertProductToOffer($arItem['ID'], $arObject, $arData['PROFILE']['HANDLER']);
              }
              else
              {
                if($arObject['EXTERNAL_ID'] == $arItem['PROPERTY_231_VALUE']) // Действия с общим товаром, где собраны Торговые пред.
                {
                  // Вычислим название, т.е. очистим от цвета и др. записей
                  $NAME = self::trimNameToColor($arObject['FIELDS']['NAME']);

                  $arObject['DATA_FIELDS']['NAME'] = $NAME;
                  $arObject['DATA_PROPERTIES']['135'] = $ARTICLE;
                  $arObject['DATA_PROPERTIES']['272'] = $arObject['ID'];
                }
                else // Все остальные товары, лишившиеся Торговых предложений
                {
                  //$arObject['DATA_FIELDS']['ACTIVE'] = 'N';
                  $arObject['DATA_PROPERTIES']['272'] = 'N';
                }
              }
            }
            elseif($arData['PROFILE']['HANDLER'] == 'HAPPYGIFTS')
            {
              // Обновление общего товара, где собраны Торговые пред.
              if($arObject['EXTERNAL_ID'] == $arItem['PROPERTY_272_VALUE'])
                $PRODUCT_ID = self::addOrUpdateProduct($arObject, $ARTICLE, $arData['PROFILE']['HANDLER'], $arItem);

              // Изменим Товар на Торговое предложени
              self::convertProductToOffer($arItem['ID'], $arObject, $arData['PROFILE']['HANDLER']);
            }
          }
          elseif($arObject['TYPE'] == 'O') // Загрузка торговых предложений
          {
            if($arObject['OFFERS_PARENT_ELEMENT'] != $arItem['ID'] && $arData['PROFILE']['HANDLER'] == 'GIFTS')
              $arObject['OFFERS_PARENT_ELEMENT'] = $arItem['ID'];
          }

          // Получим из БД ID эллемента
          /*if(empty($ObjectID))
            $ObjectID = self::SearchObject($arObject, FALSE);*/
        }
        else
        {
          // Товар с заданным артикулом не найден

          if($arObject['TYPE'] == 'E' /*&& $create_offer*/) // Загрузка элементов
          {
            if($arData['PROFILE']['HANDLER'] == 'GIFTS')
            {
              // У товара нет ТП и его артикул укорочен
              $existsOffer = self::getOfferId($arObject['EXTERNAL_ID']);
              if ((strlen($arObject['FIELDS']['PROPERTY_135']) >  strlen($ARTICLE)) && !$existsOffer)
              {
                // Создать товар, который объеденит под собой торговые предложения по общему артикулу
                $PRODUCT_ID = self::addOrUpdateProduct($arObject, $ARTICLE, $arData['PROFILE']['HANDLER']);

                // Изменить Товар на Торговое предложени
                self::convertProductToOffer($PRODUCT_ID, $arObject, $arData['PROFILE']['HANDLER']);
              }
              else
              {
                // Поменяем значения так, что бы создался товар, который объеденит под собой торговые предложения по общему корню артикульного кода
                // Вычислим название, т.е. очистим от цвета и др. записей
                $NAME = self::trimNameToColor($arObject['FIELDS']['NAME']);

                // Впишем 'название', 'артикул', обрезанный до точки, и впишем собственный PARENT_ID в 'Код Торгового предложения'
                $arObject['DATA_FIELDS']['NAME'] = $NAME;
                $arObject['DATA_PROPERTIES']['135'] = $ARTICLE;
                $arObject['DATA_PROPERTIES']['272'] = $arObject['ID'];
              }
            }
            elseif($arData['PROFILE']['HANDLER'] == 'HAPPYGIFTS')
            {
              // Создать товар, который объеденит под собой торговые предложения по общему артикулу
              $PRODUCT_ID = self::addOrUpdateProduct($arObject, $ARTICLE, $arData['PROFILE']['HANDLER']);

              // Изменить Товар на Торговое предложени
              self::convertProductToOffer($PRODUCT_ID, $arObject, $arData['PROFILE']['HANDLER']);
            }
          }
        }

        // Возможно, такое ТП уже создано. На всякий случай поищем в БД ID этого эллемента
        if(empty($ObjectID) /*&& $create_offer*/)
          $ObjectID = self::SearchObject($arObject, FALSE);
      }
    }
  }

  function webdebugAfterImport($ObjectID, $arObject, $arData, $arFields, $intResult)
  {
    if($arObject['TYPE'] != 'S')
    {
      /*if($arObject['TYPE'] == 'E' && $arObject['DATA_PROPERTIES']['272'] == 'N' && $arFields['ACTIVE'] == 'Y')
      {
        // Деактивируем товары у которых не осталось Торговых предложений
        CModule::IncludeModule('iblock');
        $el = new CIBlockElement;
        $res = $el->Update($intResult, array('ACTIVE' => 'N'));
        if(!$res)
          self::setFileContent(1, array($intResult, $res->LAST_ERROR));
      }*/

      if($arData['PROFILE']['HANDLER'] == 'HAPPYGIFTS' or $arData['PROFILE']['HANDLER'] == 'GIFTS')
      {
        // Заполнение у ТП поля "Цена в рублях после всех наценок" в Торговом каталоге
        $arPrice = \Bitrix\Catalog\Model\Price::GetList(
          array(
            'select' => array('ID', 'PRICE', 'CURRENCY'),
            'filter' => array('=PRODUCT_ID' => $intResult, '@CATALOG_GROUP_ID' => 1)
          )
        )->Fetch();
        $percentage = CExtra::GetList(array(), array("ID" => "3"), false, false, array('PERCENTAGE'))->Fetch();

        if($arPrice['PRICE'] && $percentage['PERCENTAGE'] && $arPrice['CURRENCY'] == 'RUB')
        {
          $price = $arPrice['PRICE'] + ( $arPrice['PRICE'] / 100 * $percentage['PERCENTAGE'] );
          $arFieldsprice = Array(
            'EXTRA_ID' => 3,
            'CATALOG_GROUP_ID' => 2,
            'PRICE' => $price,
            'CURRENCY' => $arPrice['CURRENCY']
          );

          $resID = \Bitrix\Catalog\Model\Price::GetList(
            array(
              'select' => array('ID'),
              'filter' => array('=PRODUCT_ID' => $intResult, '@CATALOG_GROUP_ID' => 2)
            )
          )->Fetch();
          if (empty($resID))
          {
            $arFieldsprice['PRODUCT_ID'] = $intResult;
            \Bitrix\Catalog\Model\Price::add($arFieldsprice);
          }
          else
            \Bitrix\Catalog\Model\Price::update($resID['ID'], $arFieldsprice);
        }

      }
    }

  }

  // Обрежем название товара до 'цвета'
  function trimNameToColor ($name)
  {
    global $DB;
    $resName = '';
    //$name = str_ireplace("'", " ", addslashes($name));
    $name = addslashes($name);
    $name = preg_replace('/(\d+)([,]{1})(\d+)/', '$1.$3', $name);
    $strName = str_ireplace(';', ',', $name);
    $arName = explode(',', $strName);
    foreach ($arName as $key => $value)
    {
      $arValue = explode(' ', trim($value));
      $a = TRUE;
      foreach ($arValue as $k => $v)
      {
        $f = trim(str_ireplace('/', ', ', $v));
        $s = trim(substr($v, 0, strripos($v, '_')));
        $t = trim(str_ireplace('-', ' ', $v));

        $res = $DB->Query("SELECT * FROM next_color_reference WHERE UF_NAME = '" . $f . "' OR UF_NAME = '" . $s . "' OR UF_NAME = '" . $t . "'");
        // if (!$res->SelectedRowsCount() or $k < 1)
        if (!$res->SelectedRowsCount())
        {
          if($key >= 1 && $a)
            $resName .= ', ';
          if($k >= 1)
            $resName .= ' ';
          $resName .= stripslashes($v);
        }
        else
          return $resName;
        $a = FALSE;
      }
    }
    $resName = explode(',', $resName);
    return $resName[0];
  }

  // Вычисляем артикул до точки, из строки типа '1379.60' или '613760.94/L'
  function trimArticle ($article)
  {
    $arArticle = array();
    $arArticle = explode('.', trim($article));
    $arArticle = explode('/', $arArticle[0]);
    return $arArticle[0];
  }

  // Служебная функция записывающая в файл. Используется для отладки
  function setFileContent ($num, $date)
  {
    $file = $_SERVER['DOCUMENT_ROOT'].'/upload/webdebug.import/webdebug-01.txt';
    $strNum = '';
    if($num == 1)
      $strNum .= '--------------------'.PHP_EOL;
    $strNum .= $num.'.';

    if(is_array($date))
      foreach ($date as $key => $value)
        file_put_contents($file, $strNum.$key.'. '.print_r($value, true).PHP_EOL, FILE_APPEND);
    else
      file_put_contents($file, $strNum.' '.print_r($date, true).PHP_EOL, FILE_APPEND);
  }

  // Запрос из БД всех ID торговых предложений, которые принадлежат товару
  function getOfferId ($external_id)
  {
    global $DB;
    $strSql = "SELECT ID FROM b_wdi_data WHERE TYPE='O' AND EXTERNAL_ID='".$external_id."' LIMIT 1";
    return $DB->Query($strSql, false, "File: ".__FILE__."<br>Line: ".__LINE__)->Fetch();
  }

  // Перезапись PARENT_ID в БД у торговых предложений
  function rewriteParentId ($id, $parent_id)
  {
    global $DB;
    $strSql = "UPDATE b_wdi_data SET PARENT_ID='".$parent_id."' WHERE ID='".$id."' LIMIT 1";
    return $DB->Query($strSql, false, "File: ".__FILE__."<br>Line: ".__LINE__);
  }

  // bitrix/modules/webdebug.import/handlers/happygifts.ru/class.php
  function happygiftsImageDownload($Image, $Args=array()){
    if(!class_exists('CWDI'))
      require_once($_SERVER['DOCUMENT_ROOT']."/bitrix/modules/webdebug.import/include.php");

    $arRequestParams = array(
      'URL' => $Image,
      'METHOD' => 'GET',
      'TIMEOUT' => 60,
      'SKIP_HTTPS_CHECK' => true,
      'HEADER' => "Accept: */*\r\n".
        "Accept-language: en\r\n".
        "Accept-Encoding: gzip,deflate,sdch\r\n".
        "Connection: Close\r\n",
    );
    $strData = CWDI::Request($arRequestParams);
    if(strlen($strData)>0) {
      $Dir = '/'.COption::GetOptionString('main', 'upload_dir', 'upload').'/'.WDI_MODULE.'/happygifts/tmp/';
      if(!is_dir($_SERVER['DOCUMENT_ROOT'].$Dir)) {
        mkdir($_SERVER['DOCUMENT_ROOT'].$Dir,BX_DIR_PERMISSIONS,true);
      }
      if(is_dir($_SERVER['DOCUMENT_ROOT'].$Dir)) {
        $strBasename = pathinfo($Image,PATHINFO_BASENAME);
        $strFileName = $Dir.$strBasename;
        if (file_put_contents($_SERVER['DOCUMENT_ROOT'].$strFileName, $strData)) {
          return CWDI::MakeFileArray($strFileName, false, false, $strBasename);
        }
      }
    }
    return false;
  }

  // bitrix/modules/webdebug.import/handlers/gifts.ru/class.php
  function giftsImageDownload($Image, $Args=array()){
    if(!class_exists('CWDI'))
      require_once($_SERVER['DOCUMENT_ROOT']."/bitrix/modules/webdebug.import/include.php");

    $arRequestParams = array(
      'URL' => $Image,
      'METHOD' => 'GET',
      'TIMEOUT' => 60,
      'BASIC_AUTH' => base64_encode($Args['L'].':'.$Args['P']),
      'SKIP_HTTPS_CHECK' => true,
      'HEADER' => "Accept: */*\r\n".
        "Accept-language: en\r\n".
        "Connection: Close\r\n",
      'CALLBACK_BEFORE' => array(__CLASS__,'OnBeforeRequest'),
    );
    $strData = CWDI::Request($arRequestParams);
    if($GLOBALS['WDI_LAST_HTTP_STATUS']=='200') {
      $Dir = '/'.COption::GetOptionString('main', 'upload_dir', 'upload').'/'.WDI_MODULE.'/gifts/tmp/';
      if(!is_dir($_SERVER['DOCUMENT_ROOT'].$Dir)) {
        mkdir($_SERVER['DOCUMENT_ROOT'].$Dir,BX_DIR_PERMISSIONS,true);
      }
      if(is_dir($_SERVER['DOCUMENT_ROOT'].$Dir)) {
        $strBasename = pathinfo($Image,PATHINFO_BASENAME);
        $strFileName = $Dir.$strBasename;
        if (file_put_contents($_SERVER['DOCUMENT_ROOT'].$strFileName, $strData)) {
          return CWDI::MakeFileArray($strFileName, false, false, $strBasename);
        }
      }
    }
    return false;
  }

  // Получить ID эллемента в инфоблоке
  function SearchObject($arObject, $first = TRUE)
  {
    $intResult = '';
    $arFilter = $arObject['FILTER'];
    $arFilter['IBLOCK_ID'] = $arObject['OFFERS_IBLOCK_ID'];
    if($first)
      $arFilter['PROPERTY_'.$arObject['OFFERS_PROPERTY_ID']] = $arObject['OFFERS_PARENT_ELEMENT'];
    $arFilter['CHECK_PERMISSIONS'] = 'N';
    // Получаем
    //self::setFileContent(1, array($arFilter));

    $resItem = CIBlockElement::GetList(array('ID'=>'ASC'),$arFilter,false,array('nTopCount'=>'1'),array('ID'));
    if($arItem = $resItem->GetNext(false,false)) {
      $intResult = $arItem['ID'];
      //self::setFileContent(2, array($arItem));
    }
    unset($resItem, $arItem);
    return $intResult;
  }

  // Преобразование happygifts товара в happygifts торговое предложение
  function convertProductToOffer ($element_id, &$arObject, $handler)
  {
    // Артикулы: массив исключений, пока что только для GIFTS.RU
    $arExceptions = array('1292');

    // Изменим Товар на Торговое предложени
    $arObject['TYPE'] = 'O';
    $newProperties = array();
    $newProperties[181] = $element_id; // Элемент каталога
    $newProperties[182] = self::getOfferPropertyEnum(182, 20, $arObject['DATA_PROPERTIES'][145]); // Размер
    $newProperties[183] = $arObject['DATA_PROPERTIES'][126]; // Цвет
    $newProperties[184] = $arObject['DATA_PROPERTIES'][135]; // Артикул
    $newProperties[185] = $arObject['DATA_PROPERTIES'][128]; // Картинки
    $newProperties[186] = $arObject['DATA_PROPERTIES'][224]; // Объём
    $arObject['DATA_CATALOG']['WEIGHT'] = $arObject['DATA_PROPERTIES'][223];
    switch ($handler)
    {
      case 'GIFTS':
        $newProperties[214] = $arObject['DATA_PROPERTIES'][211]; // Привязка элементов для gifts.ru

        // Обработка товаров, у которых цвета не прописываются, либо прописываются с ошибкой
        $availability = in_array(substr($newProperties[184], 0, strpos($newProperties[184], ".")), $arExceptions);
        if(empty($newProperties[183]) && $availability)
          $newProperties[183] = 'neokrashennyj';
        if(is_array($newProperties[183]) && $availability)
          $newProperties[183] = end($newProperties[183]);

        break;
      case 'HAPPYGIFTS':
        $newProperties[215] = $arObject['DATA_PROPERTIES'][212]; // Привязка элементов для happygifts.ru
        break;
    }
    unset($arObject['DATA_PROPERTIES']);
    $arObject['DATA_PROPERTIES'] = $newProperties;

    $arObject['OFFERS_IBLOCK_ID'] = '20';
    $arObject['OFFERS_PROPERTY_ID'] = '181';
    $arObject['OFFERS_PARENT_ELEMENT'] = $element_id;
  }

  // Добавление нового товара, или его обновление, объеденяющего вновь созданные Торговые предложения
  function addOrUpdateProduct ($arObject, $ARTICLE, $handler, $element=false)
  {
    CModule::IncludeModule('iblock');
    $el = new CIBlockElement;

    // Формируем массив свойств товара
    $PROP = array();
    ksort($arObject['DATA_PROPERTIES']);
    foreach ($arObject['DATA_PROPERTIES'] as $key => $value)
      $PROP[$key] = $value;
    $PROP[135] = $ARTICLE;
    $PROP[272] = $arObject['EXTERNAL_ID'];
    unset($PROP[128]);
    //unset($PROP[211]);
    //unset($PROP[212]);

    //self::setFileContent(3, array($PROP[211], $PROP[212], $handler));

    if(isset($PROP[211]) && !empty($PROP[211]) && $handler == 'GIFTS')
    {
      $PROP[211] = TRUE;
      $PROP[212] = '0';
    }
    elseif (isset($PROP[212]) && !empty($PROP[212]) && $handler == 'HAPPYGIFTS')
    {
      $PROP[212] = TRUE;
      $PROP[211] = '0';
    }

    //self::setFileContent(4, array($PROP));

    $arLoadProductArray = Array(
      'MODIFIED_BY'       => $GLOBALS['USER']->GetID(),
      'PROPERTY_VALUES'   => $PROP,
      'NAME'              => self::trimNameToColor($arObject['DATA_FIELDS']['NAME']),
      'PREVIEW_TEXT'      => $arObject['DATA_FIELDS']['PREVIEW_TEXT']['TEXT'],
      'PREVIEW_TEXT_TYPE' => $arObject['DATA_FIELDS']['PREVIEW_TEXT']['TYPE'],
      'DETAIL_TEXT'       => $arObject['DATA_FIELDS']['DETAIL_TEXT']['TEXT'],
      'DETAIL_TEXT_TYPE'  => $arObject['DATA_FIELDS']['PREVIEW_TEXT']['TYPE'],
    );

    switch ($handler)
    {
      case 'GIFTS':
        $arLoadProductArray['PREVIEW_PICTURE'] = self::giftsImageDownload(
          $arObject['DATA_FIELDS']['PREVIEW_PICTURE']['SRC'],
          $arObject['DATA_FIELDS']['PREVIEW_PICTURE']['CALLBACK_ARGS']
        );
        $arLoadProductArray['DETAIL_PICTURE'] = self::giftsImageDownload(
          $arObject['DATA_FIELDS']['DETAIL_PICTURE']['SRC'],
          $arObject['DATA_FIELDS']['DETAIL_PICTURE']['CALLBACK_ARGS']
        );
        break;
      case 'HAPPYGIFTS':
        $arLoadProductArray['PREVIEW_PICTURE'] = self::happygiftsImageDownload(
          $arObject['DATA_FIELDS']['PREVIEW_PICTURE']['SRC'],
          $arObject['DATA_FIELDS']['PREVIEW_PICTURE']['CALLBACK_ARGS']
        );
        $arLoadProductArray['DETAIL_PICTURE'] = self::happygiftsImageDownload(
          $arObject['DATA_FIELDS']['DETAIL_PICTURE']['SRC'],
          $arObject['DATA_FIELDS']['DETAIL_PICTURE']['CALLBACK_ARGS']
        );
        break;
    }

    if($element == FALSE || (isset($element) && empty($element['IBLOCK_SECTION_ID'])))
    {
      // Раздел в котором будет находится товар
      $OBJECT_ID = $arObject['SECTION_ID'];
      global $DB;
      $strSql = "SELECT OBJECT_ID FROM b_wdi_data WHERE ID='".$arObject['PARENT_ID']."'";
      $db_res = $DB->Query($strSql, false, "File: ".__FILE__."<br>Line: ".__LINE__);
      if ($res = $db_res->Fetch())
        $OBJECT_ID = $res['OBJECT_ID'];
      $arLoadProductArray['IBLOCK_SECTION_ID'] = $OBJECT_ID;
    }

    if ($element !== FALSE)
    {
      $res = $el->Update($element['ID'], $arLoadProductArray);
      if(!$res)
        self::setFileContent(2, array($element['ID'], $res->LAST_ERROR));
    }
    else
    {
      $arLoadProductArray['IBLOCK_ID'] = $arObject['IBLOCK_ID'];
      $arLoadProductArray['ACTIVE'] = 'Y';

      $res = $el->Add($arLoadProductArray, false, true, true);
      if(!$res)
        self::setFileContent(3, array($ARTICLE, $res->LAST_ERROR));
    }

    return $res;
  }

  // Получаем ID по значению свойства типа "список".
  function getOfferPropertyEnum ($prop_id, $iblock_id, $value)
  {
    if(isset($value) && !empty($value))
    {
      $resItem = CIBlockPropertyEnum::GetList(
        array('ID' => 'ASC'),
        array(
          'IBLOCK_ID' => $iblock_id,
          'PROPERTY_ID' => $prop_id,
          'VALUE' => $value
        )
      )->GetNext(false,false);
      if($resItem)
        return $resItem['ID'];
      else
      {
        $ibpenum = new CIBlockPropertyEnum;
        $arAddFields = array(
          'IBLOCK_ID' => $iblock_id,
          'PROPERTY_ID' => $prop_id,
          'VALUE' => $value,
          'SORT' => 500,
          'EXTERNAL_ID' => ToLower(MD5($value)),
        );
        $EnumID = $ibpenum->Add($arAddFields);
        if($EnumID)
          return $EnumID;
        else
          return FALSE;
      }
    }
    else
      return FALSE;
  }

}
/* END Обработчик данны для добавления товаров с одним арткулом как торговые предложения */
