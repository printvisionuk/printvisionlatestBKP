<?php
/**
 * Copyright © MageWorx. All rights reserved.
 * See LICENSE.txt for license details.
 */
namespace MageWorx\OptionBase\Api;

interface InstallSchemaInterface
{
    /**
     * Get module table prefix
     *
     * @return string
     */
    public function getModuleTablePrefix();

    /**
     * Get column data
     *
     * @return array
     */
    public function getData();

    /**
     * Get indexes
     *
     * @return array
     */
    public function getIndexes();

    /**
     * Get foreign keys
     *
     * @return array
     */
    public function getForeignKeys();
}
