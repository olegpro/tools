<?php
/**
 * @link https://github.com/bitrix-expert/tools
 * @copyright Copyright © 2015 Nik Samokhvalov
 * @license MIT
 */

namespace Bex\Tools\Iblock;

use Bex\Tools\Finder;
use Bex\Tools\ValueNotFoundException;
use Bitrix\Iblock\IblockTable;
use Bitrix\Iblock\PropertyEnumerationTable;
use Bitrix\Iblock\PropertyTable;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentNullException;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;

/**
 * Finder of the info blocks and properties of the info blocks.
 * 
 * @author Nik Samokhvalov <nik@samokhvalov.info>
 */
class IblockFinder extends Finder
{
    /**
     * Code of the shard cache for iblocks IDs.
     */
    const CACHE_SHARD_LITE = 'lite';
    /**
     * Code of the shard cache for properties.
     * @deprecated Not used.
     */
    const CACHE_PROPS_SHARD = 'props';

    protected static $cacheDir = 'bex_tools/iblocks';
    protected $id;
    protected $type;
    protected $code;

    /**
     * @inheritdoc
     *
     * @throws ArgumentNullException Empty parameters in the filter
     * @throws LoaderException Module "iblock" not installed
     */
    public function __construct(array $filter, $silenceMode = false)
    {
        if (!Loader::includeModule('iblock'))
        {
            throw new LoaderException('Failed include module "iblock"');
        }
        
        parent::__construct($filter, $silenceMode);

        $filter = $this->prepareFilter($filter);

        if (isset($filter['type']))
        {
            $this->type = $filter['type'];
        }

        if (isset($filter['code']))
        {
            $this->code = $filter['code'];
        }

        if (isset($filter['id']))
        {
            $this->id = $filter['id'];
        }

        if (!isset($this->id))
        {
            $this->id = $this->getFromCache(
                ['type' => 'id'],
                static::CACHE_SHARD_LITE
            );
        }
    }

    /**
     * Gets iblock ID.
     *
     * @return integer
     */
    public function id()
    {
        return $this->id;
    }

    /**
     * Gets iblock type.
     *
     * @return string
     */
    public function type()
    {
        return $this->getFromCache(
            ['type' => 'type'],
            $this->id
        );
    }

    /**
     * Gets iblock code.
     *
     * @return string
     */
    public function code()
    {
        return $this->getFromCache(
            ['type' => 'code'],
            $this->id
        );
    }

    /**
     * Gets property ID.
     *
     * @param string $code Property code
     *
     * @return integer
     */
    public function propId($code)
    {
        return $this->getFromCache(
            ['type' => 'propId', 'propCode' => $code],
            $this->id
        );
    }

    /**
     * Gets property enum value ID.
     *
     * @param string $code Property code
     * @param int $valueXmlId Property enum value XML ID
     *
     * @return integer
     */
    public function propEnumId($code, $valueXmlId)
    {
        return $this->getFromCache(
            ['type' => 'propEnumId', 'propCode' => $code, 'valueXmlId' => $valueXmlId],
            $this->id
        );
    }

    /**
     * @inheritdoc
     * 
     * @throws ArgumentNullException Empty parameters in the filter
     */
    protected function prepareFilter(array $filter)
    {
        foreach ($filter as $code => &$value)
        {
            if ($code === 'id' || $code === 'propId')
            {
                intval($value);

                if ($value <= 0)
                {
                    throw new ArgumentNullException($code);
                }
            }
            else
            {
                trim(htmlspecialchars($value));

                if (strlen($value) <= 0)
                {
                    throw new ArgumentNullException($code);
                }
            }
        }

        return $filter;
    }

    /**
     * @inheritdoc
     */
    protected function getValue(array $cache, array $filter, $shard)
    {
        if ($shard === static::CACHE_SHARD_LITE)
        {
            if (!isset($this->type))
            {
                throw new ArgumentNullException('type');
            }
            elseif (!isset($this->code))
            {
                throw new ArgumentNullException('code');
            }

            switch ($filter['type'])
            {
                case 'id':
                    $value = (int) $cache[$this->type][$this->code];

                    if ($value <= 0)
                    {
                        throw new ValueNotFoundException('Iblock ID', 'type "' . $this->type . '" and code "'
                            . $this->code . '"');
                    }

                    return $value;
                    break;

                default:
                    throw new \InvalidArgumentException('Invalid type on filter');
                    break;
            }
        }
        else
        {
            switch ($filter['type'])
            {
                case 'type':
                    $value = (string) $cache['TYPE'];

                    if (strlen($value) <= 0)
                    {
                        throw new ValueNotFoundException('Iblock type', 'iblock #' . $this->id);
                    }

                    return $value;
                    break;

                case 'code':
                    $value = (string) $cache['CODE'];

                    if (strlen($value) <= 0)
                    {
                        throw new ValueNotFoundException('Iblock code', 'iblock #' . $this->id);
                    }

                    return $value;
                    break;

                case 'propId':
                    $value = (int) $cache['PROPS_ID'][$filter['propCode']];

                    if ($value <= 0)
                    {
                        throw new ValueNotFoundException('Property ID', 'iblock #' . $this->id . ' and property code "'
                            . $filter['propCode'] . '"');
                    }

                    return $value;
                    break;

                case 'propEnumId':
                    $value = (int) $cache['PROPS_ENUM_ID'][$filter['code']][$filter['valueXmlId']];

                    if ($value <= 0)
                    {
                        throw new ValueNotFoundException('Property enum ID', 'iblock #' . $this->id . ', property code "'
                            . $filter['propCode'] . '" and property XML ID "' . $filter['valueXmlId'] . '"');
                    }

                    return $value;
                    break;

                default:
                    throw new \InvalidArgumentException('Invalid type on filter');
                    break;
            }
        }
    }

    /**
     * @inheritdoc
     */
    protected function getItems($shard)
    {
        if ($shard === static::CACHE_SHARD_LITE)
        {
            return $this->getLiteShard();
        }
        else
        {
            return $this->getIblockShard();
        }
    }
    
    protected function getLiteShard()
    {
        $items = [];
        $iblockIds = [];

        $rsIblocks = IblockTable::getList([
            'select' => [
                'IBLOCK_TYPE_ID',
                'ID',
                'CODE'
            ]
        ]);

        while ($iblock = $rsIblocks->fetch())
        {
            if ($iblock['CODE'])
            {
                $items[$iblock['IBLOCK_TYPE_ID']][$iblock['CODE']] = $iblock['ID'];

                $iblockIds[] = $iblock['ID'];
            }
        }

        if (!empty($iblockIds))
        {
            $this->registerCacheTags($iblockIds);
        }
        
        return $items;
    }
    
    protected function getIblockShard()
    {
        $items = [];

        $rsIblocks = IblockTable::getList([
            'filter' => [
                'ID' => $this->id
            ],
            'select' => [
                'IBLOCK_TYPE_ID',
                'CODE'
            ]
        ]);

        if ($iblock = $rsIblocks->fetch())
        {
            if ($iblock['CODE'])
            {
                $items['CODE'] = $iblock['CODE'];
            }

            $items['TYPE'] = $iblock['IBLOCK_TYPE_ID'];
        }
        
        if (empty($items))
        {
            throw new ValueNotFoundException('Iblock', 'ID #' . $this->id);
        }

        $rsProps = PropertyTable::getList([
            'filter' => [
                'IBLOCK_ID' => $this->id
            ],
            'select' => [
                'ID',
                'CODE',
                'IBLOCK_ID'
            ]
        ]);

        while ($prop = $rsProps->fetch())
        {
            $items['PROPS_ID'][$prop['CODE']] = $prop['ID'];
        }

        $rsPropsEnum = PropertyEnumerationTable::getList([
            'filter' => [
                'PROPERTY_ID' => $this->id
            ],
            'select' => [
                'ID',
                'XML_ID',
                'PROPERTY_ID',
                'PROPERTY_CODE' => 'PROPERTY.CODE'
            ]
        ]);

        while ($propEnum = $rsPropsEnum->fetch())
        {
            if ($propEnum['PROPERTY_CODE'])
            {
                $items['PROPS_ENUM_ID'][$propEnum['PROPERTY_CODE']][$propEnum['XML_ID']] = $propEnum['ID'];
            }
        }

        $this->registerCacheTags($this->id);

        return $items;
    }

    protected function registerCacheTags($iblockIds)
    {
        if (!is_array($iblockIds) || empty($iblockIds))
        {
            return;
        }
        
        foreach ($iblockIds as $id)
        {
            Application::getInstance()->getTaggedCache()->registerTag('iblock_id_' . $id);
        }

        Application::getInstance()->getTaggedCache()->registerTag('iblock_id_new');
    }
}
