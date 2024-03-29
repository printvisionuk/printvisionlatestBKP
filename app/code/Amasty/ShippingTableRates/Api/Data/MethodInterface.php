<?php
/**
 * @author Amasty Team
 * @copyright Copyright (c) 2022 Amasty (https://www.amasty.com)
 * @package Shipping Table Rates for Magento 2
 */

namespace Amasty\ShippingTableRates\Api\Data;

interface MethodInterface
{
    /**#@+
     * Constants defined for keys of data array
     */
    public const ID = 'id';
    public const IS_ACTIVE = 'is_active';
    public const NAME = 'name';
    public const COMMENT = 'comment';
    public const STORES = 'stores';
    public const CUST_GROUPS = 'cust_groups';
    public const SELECT_RATE = 'select_rate';
    public const MIN_RATE = 'min_rate';
    public const MAX_RATE = 'max_rate';
    public const FREE_TYPES = 'free_types';
    public const COMMENT_IMG = 'comment_img';
    public const WEIGHT_TYPE = 'weight_type';
    /**#@-*/

    /**
     * @return int
     */
    public function getId();

    /**
     * @param int $id
     *
     * @return \Amasty\ShippingTableRates\Api\Data\MethodInterface
     */
    public function setId($id);

    /**
     * @return int
     */
    public function getIsActive();

    /**
     * @param int $isActive
     *
     * @return \Amasty\ShippingTableRates\Api\Data\MethodInterface
     */
    public function setIsActive($isActive);

    /**
     * @return string|null
     */
    public function getName();

    /**
     * @param string|null $name
     *
     * @return \Amasty\ShippingTableRates\Api\Data\MethodInterface
     */
    public function setName($name);

    /**
     * @return string|null
     */
    public function getComment();

    /**
     * @param string|null $comment
     *
     * @return \Amasty\ShippingTableRates\Api\Data\MethodInterface
     */
    public function setComment($comment);

    /**
     * @return string
     */
    public function getStores();

    /**
     * @param string $stores
     *
     * @return \Amasty\ShippingTableRates\Api\Data\MethodInterface
     */
    public function setStores($stores);

    /**
     * @return string
     */
    public function getCustGroups();

    /**
     * @param string $custGroups
     *
     * @return \Amasty\ShippingTableRates\Api\Data\MethodInterface
     */
    public function setCustGroups($custGroups);

    /**
     * @return int
     */
    public function getSelectRate();

    /**
     * @param int $selectRate
     *
     * @return \Amasty\ShippingTableRates\Api\Data\MethodInterface
     */
    public function setSelectRate($selectRate);

    /**
     * @return float
     */
    public function getMinRate();

    /**
     * @param float $minRate
     *
     * @return \Amasty\ShippingTableRates\Api\Data\MethodInterface
     */
    public function setMinRate($minRate);

    /**
     * @return float
     */
    public function getMaxRate();

    /**
     * @param float $maxRate
     *
     * @return \Amasty\ShippingTableRates\Api\Data\MethodInterface
     */
    public function setMaxRate($maxRate);

    /**
     * @return string
     */
    public function getFreeTypes();

    /**
     * @param string $freeTypes
     *
     * @return \Amasty\ShippingTableRates\Api\Data\MethodInterface
     */
    public function setFreeTypes($freeTypes);

    /**
     * @return string|null
     */
    public function getCommentImg();

    /**
     * @param string|null $commentImg
     *
     * @return \Amasty\ShippingTableRates\Api\Data\MethodInterface
     */
    public function setCommentImg($commentImg);

    /**
     * @return int
     */
    public function getWeightType();

    /**
     * @param int $weightType
     *
     * @return \Amasty\ShippingTableRates\Api\Data\MethodInterface
     */
    public function setWeightType($weightType);
}
