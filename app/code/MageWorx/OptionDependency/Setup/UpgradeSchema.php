<?php
/**
 * Copyright © MageWorx. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace MageWorx\OptionDependency\Setup;

use Magento\Framework\Setup\UpgradeSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use MageWorx\OptionBase\Helper\Data as BaseHelper;
use MageWorx\OptionDependency\Model\Config as DependencyModel;
use MageWorx\OptionDependency\Model\DependencyRules;
use MageWorx\OptionDependency\Model\HiddenDependents;

class UpgradeSchema implements UpgradeSchemaInterface
{
    /**
     * @var \MageWorx\OptionBase\Model\Installer
     */
    protected $optionBaseInstaller;

    /**
     * @var SchemaSetupInterface
     */
    protected $setup;

    /**
     * @var BaseHelper
     */
    protected $baseHelper;

    /**
     * @var DependencyRules
     */
    protected $dependencyRules;

    /**
     * @var HiddenDependents
     */
    protected $hiddenDependents;

    /**
     * UpgradeSchema constructor.
     *
     * @param \MageWorx\OptionBase\Model\Installer $optionBaseInstaller
     * @param BaseHelper $baseHelper
     * @param DependencyRules $dependencyRules
     * @param HiddenDependents $hiddenDependents
     */
    public function __construct(
        \MageWorx\OptionBase\Model\Installer $optionBaseInstaller,
        BaseHelper $baseHelper,
        DependencyRules $dependencyRules,
        HiddenDependents $hiddenDependents
    ) {
        $this->optionBaseInstaller = $optionBaseInstaller;
        $this->baseHelper          = $baseHelper;
        $this->dependencyRules     = $dependencyRules;
        $this->hiddenDependents    = $hiddenDependents;
    }

    /**
     * {@inheritdoc}
     */
    public function upgrade(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $this->setup = $setup;
        $this->optionBaseInstaller->install();

        if (version_compare($context->getVersion(), '2.0.5', '<')) {
            $setup->getConnection()->update(
                $setup->getTable('core_config_data'),
                ['path' => 'mageworx_apo/optiondependency/use_title_id'],
                "path = 'mageworx_optiondependency/main/use_title_id'"
            );
        }

        if (version_compare($context->getVersion(), '2.0.7', '<')) {
            $this->processFields();
            $this->setup->getConnection()->beginTransaction();
            try {
                $this->convertDependencyOptionMageWorxIds();
                $this->convertDependencyOptionValueMageWorxIds();
                $this->setup->getConnection()->commit();
            } catch (\Exception $e) {
                $this->setup->getConnection()->rollback();
                throw($e);
            }
        }

        if (version_compare($context->getVersion(), '2.0.10', '<')) {
            if ($this->setup->getConnection()->tableColumnExists(
                $this->setup->getTable('mageworx_optionbase_product_attributes'),
                'dependency_rules'
            )) {
                $this->setup->getConnection()->changeColumn(
                    $this->setup->getTable('mageworx_optionbase_product_attributes'),
                    'dependency_rules',
                    'dependency_rules',
                    [
                        'type' => \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
                        'nullable' => false,
                        'length'   => '67000',
                        'default'  => '',
                        'comment'  => 'Dependency Rules (Added by MageWorx_OptionDependency)',
                    ]
                );
            }

            $this->setup->getConnection()->beginTransaction();
            try {
                $this->processDependencyRulesUpdate();
                $this->processPreselectedValuesUpdate();
                $this->setup->getConnection()->commit();
            } catch (\Exception $e) {
                $this->setup->getConnection()->rollback();
                throw($e);
            }
        }
    }

    /**
     * Collect dependency rules and initial state, save to the database
     *
     * @return void
     */
    protected function processDependencyRulesUpdate()
    {

        $productDependencyIds = $this->getProductIdsSelect();

        if (!$productDependencyIds) {
            return;
        }

        $totalIds = count($productDependencyIds);
        $limit    = 50;

        for ($offset = 0; $offset < $totalIds; $offset += $limit) {
            $ids      = array_slice($productDependencyIds, $offset, $limit);
            $products = [];

            $this->collectValues($products, $ids);
            $this->collectOptions($products, $ids);

            if (!$products) {
                continue;
            }

            $dependenciesPerProducts = $this->getDependencies($ids);
            $this->processProductAttributesData($productAttributesData, $ids);

            $toSave = [];
            $i      = 0;
            foreach ($products as $productId => $productData) {
                $dependencyRules = [];
                if (!empty($dependenciesPerProducts[$productId])) {
                    $dependencies    = $this->dependencyRules->getPreparedDependencies(
                        $dependenciesPerProducts[$productId]
                    );
                    $dependencyRules = $this->dependencyRules->combineRules($dependencies, $productData['options']);
                }

                $isDefaults       = $this->getIsDefaults($productData['options']);
                $hiddenDependents = $this->hiddenDependents->getHiddenDependents(
                    $productData['options'],
                    $dependencyRules,
                    $isDefaults
                );

                $productAttributesData[$productId]['product_id']        = $productId;
                $productAttributesData[$productId]['dependency_rules']  = $this->baseHelper->jsonEncode(
                    $dependencyRules
                );
                $productAttributesData[$productId]['hidden_dependents'] = $this->baseHelper->jsonEncode(
                    $hiddenDependents
                );

                $toSave[] = $productAttributesData[$productId];
                $i++;

                if ($i === 10) {
                    $this->insertMultipleProductAttributes($toSave);
                    $toSave = [];
                    $i      = 0;
                }
            }

            if ($toSave) {
                $this->insertMultipleProductAttributes($toSave);
            }
        }
    }

    /**
     * Collect preselect values, save to the database
     *
     * @return void
     */
    protected function processPreselectedValuesUpdate() {

        if (!$this->setup->tableExists('mageworx_optionfeatures_option_type_is_default')) {
            return;
        }

        $productIsDefaultIdsSelect = $this->setup->getConnection()->select()
            ->distinct()
            ->from(
                ['cpo' => $this->setup->getTable('catalog_product_option')],
                ['product_id']
            )
            ->join(
                ['cpotv' => $this->setup->getTable('catalog_product_option_type_value')],
                'cpo.option_id = cpotv.option_id',
                []
            )
            ->join(
                ['mcpotid' => $this->setup->getTable('mageworx_optionfeatures_option_type_is_default')],
                'cpotv.option_type_id = mcpotid.option_type_id',
                []
            )
            ->where('mcpotid.is_default = 1');

        $productIsDefaultIds = $this->setup->getConnection()->fetchCol($productIsDefaultIdsSelect);
        $productDependencyIds = $this->getProductIdsSelect();
        $productIsDefaultOnlyIds = array_diff($productIsDefaultIds, $productDependencyIds);

        if (!$productIsDefaultOnlyIds) {
            return;
        }

        $totalIds = count($productIsDefaultOnlyIds);
        $limit    = 50;

        for ($offset = 0; $offset < $totalIds; $offset += $limit) {
            $ids      = array_slice($productIsDefaultOnlyIds, $offset, $limit);
            $products = [];

            $this->collectIsDefaultValues($products, $ids);

            if (!$products) {
                continue;
            }

            $this->processProductAttributesData($productAttributesData, $ids);

            $toSave = [];
            $i      = 0;
            foreach ($products as $productId => $productData) {
                $isDefaults = $this->getIsDefaults($productData['options']);

                $hiddenDependents = $this->hiddenDependents->getHiddenDependents(
                    $productData['options'],
                    [],
                    $isDefaults
                );

                $productAttributesData[$productId]['product_id']        = $productId;
                $productAttributesData[$productId]['dependency_rules']  = '[]';
                $productAttributesData[$productId]['hidden_dependents'] = $this->baseHelper->jsonEncode(
                    $hiddenDependents
                );

                $toSave[] = $productAttributesData[$productId];
                $i++;

                if ($i === 10) {
                    $this->insertMultipleProductAttributes($toSave);
                    $toSave = [];
                    $i      = 0;
                }
            }

            if ($toSave) {
                $this->insertMultipleProductAttributes($toSave);
            }
        }

    }

    /**
     * @return array
     */
    protected function getProductIdsSelect()
    {
        $productIdsSelect = $this->setup->getConnection()->select()
            ->distinct()
            ->from($this->setup->getTable(DependencyModel::TABLE_NAME), 'product_id');

        return $this->setup->getConnection()->fetchCol($productIdsSelect);
    }

    /**
     * insertMultiple APO product attributes
     *
     * @param array $data
     * @return void
     */
    protected function insertMultipleProductAttributes($data)
    {
        $this->setup->getConnection()->insertMultiple(
            $this->setup->getTable('mageworx_optionbase_product_attributes'),
            $data
        );
    }

    protected function collectIsDefaultValues(&$products, $ids)
    {
        if (!$this->setup->tableExists('mageworx_optionfeatures_option_type_is_default')) {
            return;
        }

        $valueSelect = $this->setup->getConnection()->select()
            ->from(
                ['mcpotid' => $this->setup->getTable('mageworx_optionfeatures_option_type_is_default')],
                ['option_type_id', 'is_default']
            )
            ->join(
                ['cpotv' => $this->setup->getTable('catalog_product_option_type_value')],
                'cpotv.option_type_id = mcpotid.option_type_id',
                []
            )
            ->join(
                ['cpo' => $this->setup->getTable('catalog_product_option')],
                'cpo.option_id = cpotv.option_id',
                ['option_id', 'product_id']
            )
        ->where('mcpotid.is_default = 1');
        $valueSelect->where('cpo.product_id IN (?)', $ids);

        $fetchedValues = $this->setup->getConnection()->fetchAll($valueSelect);

        foreach ($fetchedValues as $fetchedValue) {

            $products[$fetchedValue['product_id']]['options'][$fetchedValue['option_id']]['option_id'] = $fetchedValue['option_id'];

            $products[$fetchedValue['product_id']]['options'][$fetchedValue['option_id']]['values'][$fetchedValue['option_type_id']] = [
                'option_type_id'  => $fetchedValue['option_type_id'],
                'is_default'      => $fetchedValue['is_default']
            ];
        }
    }

    /**
     * Collect values during update to dependency rules
     *
     * @param array $products
     * @param array $ids
     * @return void
     */
    protected function collectValues(&$products, $ids)
    {
        $valueSelect = $this->setup->getConnection()->select()
                                   ->from(
                                       ['cpotv' => $this->setup->getTable('catalog_product_option_type_value')],
                                       ['option_type_id', 'dependency_type']
                                   )
                                   ->joinLeft(
                                       ['cpo' => $this->setup->getTable('catalog_product_option')],
                                       "cpo.option_id = cpotv.option_id",
                                       ['option_id', 'product_id', 'type']
                                   );
        if ($this->setup->tableExists('mageworx_optionfeatures_option_type_is_default')) {
            $valueSelect->joinLeft(
                ['isdef' => $this->setup->getTable('mageworx_optionfeatures_option_type_is_default')],
                "isdef.option_type_id = cpotv.option_type_id AND isdef.store_id = 0",
                'is_default'
            );
        }

        $valueSelect->where('cpo.product_id IN (?)', $ids);
        $fetchedValues = $this->setup->getConnection()->fetchAll($valueSelect);

        foreach ($fetchedValues as $fetchedValue) {
            $products[$fetchedValue['product_id']]['options'][$fetchedValue['option_id']]['option_id'] = $fetchedValue['option_id'];

            $products[$fetchedValue['product_id']]['options'][$fetchedValue['option_id']]['values'][$fetchedValue['option_type_id']] = [
                'option_type_id'  => $fetchedValue['option_type_id'],
                'is_default'      => $fetchedValue['is_default'],
                'dependency_type' => $fetchedValue['dependency_type'],
                'type'            => $fetchedValue['type']
            ];
        }
    }

    /**
     * Collect options during update to dependency rules
     *
     * @param array $products
     * @param array $ids
     * @return void
     */
    protected function collectOptions(&$products, $ids)
    {
        $optionSelect = $this->setup->getConnection()->select()
                                    ->from(
                                        ['cpo' => $this->setup->getTable('catalog_product_option')],
                                        ['option_id', 'dependency_type', 'product_id', 'type']
                                    )
                                    ->where("cpo.product_id IN (?)", $ids)
                                    ->where("type NOT IN ('drop_down','checkbox','radio','multiple')");

        $fetchedOptions = $this->setup->getConnection()->fetchAll($optionSelect);
        foreach ($fetchedOptions as $fetchedOption) {
            $products[$fetchedOption['product_id']]['options'][$fetchedOption['option_id']] = [
                'option_id'       => $fetchedOption['option_id'],
                'dependency_type' => $fetchedOption['dependency_type'],
                'type'            => $fetchedOption['type']
            ];
        }
    }

    /**
     * Collect dependencies during update to dependency rules
     *
     * @param array $ids
     * @return array
     */
    protected function getDependencies($ids)
    {
        $dependencySelect = $this->setup->getConnection()->select()
                                        ->from(
                                            $this->setup->getTable('mageworx_option_dependency') . ' AS depen',
                                            [
                                                'child_option_type_id',
                                                'child_option_id',
                                                'parent_option_type_id',
                                                'parent_option_id',
                                                'product_id'
                                            ]
                                        )
                                        ->where(
                                            "depen.product_id IN (" . implode(',', $ids) . ")"
                                        );

        $fetchedDependencies    = $this->setup->getConnection()->fetchAll($dependencySelect);
        $dependenciesPerProduct = [];
        foreach ($fetchedDependencies as $fetchedDependency) {
            $dependenciesPerProduct[$fetchedDependency['product_id']][] = $fetchedDependency;
        }
        return $dependenciesPerProduct;
    }

    /**
     * Process product attributes data during update to dependency rules
     *
     * @param array $productAttributesData
     * @param array $ids
     * @return void
     */
    protected function processProductAttributesData(&$productAttributesData, $ids)
    {
        $productAttributesSelect = $this->setup->getConnection()->select()
                                               ->from(
                                                   $this->setup->getTable(
                                                       'mageworx_optionbase_product_attributes'
                                                   )
                                               )
                                               ->where("product_id IN (" . implode(',', $ids) . ")");

        $fetchedProductAttributesData = $this->setup->getConnection()->fetchAll($productAttributesSelect);

        foreach ($fetchedProductAttributesData as $fetchedProductAttributesDatum) {
            $productAttributesData[$fetchedProductAttributesDatum['product_id']] = $fetchedProductAttributesDatum;
        }

        $this->setup->getConnection()->delete(
            $this->setup->getTable('mageworx_optionbase_product_attributes'),
            "product_id IN (" . implode(',', $ids) . ")"
        );
    }

    /**
     * Get product collection using selected product IDs
     *
     * @param array $options
     * @return array
     */
    protected function getIsDefaults($options)
    {
        $isDefaults = [];
        foreach ($options as $option) {
            if (empty($option['values'])) {
                continue;
            }
            foreach ($option['values'] as $value) {
                if (empty($value['is_default'])) {
                    continue;
                }
                $isDefaults[] = [
                    'option_type_id' => $value['option_type_id'],
                    'is_default'     => $value['is_default']
                ];
            }
        }
        return $isDefaults;
    }

    /**
     * Out if column doesn't exist
     *
     * @param string $table
     * @param string $column
     * @return bool
     */
    protected function out($table, $column)
    {
        return !$this->setup->getConnection()->tableColumnExists($this->setup->getTable($table), $column);
    }

    /**
     * Process fields due to removing mageworx_ids:
     * Copy old data to temporary field
     * Get option_id/option_type_id equivalent for mageworx_option_id/mageworx_option_type_id
     */
    protected function processFields()
    {
        $tableNames = [
            DependencyModel::TABLE_NAME,
            DependencyModel::OPTIONTEMPLATES_TABLE_NAME
        ];

        foreach ($tableNames as $tableName) {
            $this->moveMageWorxIdsToTemporaryFields($tableName);
            $this->modifyParentAndChildColumnDefinition($tableName);
        }
    }

    /**
     * @param string $tableName
     */
    protected function moveMageWorxIdsToTemporaryFields($tableName)
    {
        $data = [
            DependencyModel::COLUMN_NAME_CHILD_MAGEWORX_OPTION_ID       =>
                new \Zend_Db_Expr(DependencyModel::COLUMN_NAME_CHILD_OPTION_ID),
            DependencyModel::COLUMN_NAME_CHILD_MAGEWORX_OPTION_TYPE_ID  =>
                new \Zend_Db_Expr(DependencyModel::COLUMN_NAME_CHILD_OPTION_TYPE_ID),
            DependencyModel::COLUMN_NAME_PARENT_MAGEWORX_OPTION_ID      =>
                new \Zend_Db_Expr(DependencyModel::COLUMN_NAME_PARENT_OPTION_ID),
            DependencyModel::COLUMN_NAME_PARENT_MAGEWORX_OPTION_TYPE_ID =>
                new \Zend_Db_Expr(DependencyModel::COLUMN_NAME_PARENT_OPTION_TYPE_ID),
            DependencyModel::COLUMN_NAME_IS_PROCESSED                   => 1
        ];
        $this->setup->getConnection()->update(
            $this->setup->getTable($tableName),
            $data,
            [DependencyModel::COLUMN_NAME_IS_PROCESSED . ' = ?' => '0']
        );
    }

    /**
     * Modify column definition of child/parent option/option_value fields to contain integer IDs
     *
     * @param string $tableName
     * @return void
     */
    protected function modifyParentAndChildColumnDefinition($tableName)
    {
        $fieldMap = [
            DependencyModel::COLUMN_NAME_CHILD_OPTION_ID,
            DependencyModel::COLUMN_NAME_CHILD_OPTION_TYPE_ID,
            DependencyModel::COLUMN_NAME_PARENT_OPTION_ID,
            DependencyModel::COLUMN_NAME_PARENT_OPTION_TYPE_ID
        ];

        foreach ($fieldMap as $field) {
            $table = $this->setup->getConnection()->describeTable($this->setup->getTable($tableName));

            if (!empty($table[$field]['DATA_TYPE']) && $table[$field]['DATA_TYPE'] === 'varchar') {
                $this->setup->getConnection()->modifyColumn(
                    $this->setup->getTable($tableName),
                    $field,
                    [
                        'type'     => \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER,
                        'length'   => 10,
                        'nullable' => false,
                        'default'  => 0
                    ],
                    true
                );
                $indexType = \Magento\Framework\DB\Adapter\AdapterInterface::INDEX_TYPE_INDEX;
                $this->setup->getConnection()->addIndex(
                    $this->setup->getTable($tableName),
                    $this->setup->getIdxName($tableName, $field, $indexType),
                    $field,
                    $indexType
                );
            }
        }
    }

    /**
     * Update new option_id with mageworx_option_id equivalent for dependency child/parent option
     */
    protected function convertDependencyOptionMageWorxIds()
    {
        $tableNames = [
            DependencyModel::TABLE_NAME                 => 'catalog_product_option',
            DependencyModel::OPTIONTEMPLATES_TABLE_NAME => 'mageworx_optiontemplates_group_option'
        ];

        foreach ($tableNames as $mainTable => $joinedTable) {

            if ($this->out($joinedTable, 'mageworx_option_id')) {
                continue;
            }

            $fieldMap = [
                DependencyModel::COLUMN_NAME_CHILD_MAGEWORX_OPTION_ID  => DependencyModel::COLUMN_NAME_CHILD_OPTION_ID,
                DependencyModel::COLUMN_NAME_PARENT_MAGEWORX_OPTION_ID => DependencyModel::COLUMN_NAME_PARENT_OPTION_ID
            ];

            foreach ($fieldMap as $oldColumnName => $newColumnName) {
                $select = $this->setup
                    ->getConnection()
                    ->select()
                    ->joinLeft(
                        [
                            'cpo' => $this->setup->getTable($joinedTable)
                        ],
                        'cpo.mageworx_option_id = option_dependency.' . $oldColumnName,
                        [
                            $newColumnName => 'option_id'
                        ]
                    )
                    ->where(
                        "option_dependency." . $oldColumnName . " IS NOT NULL"
                    );

                $update = $this->setup
                    ->getConnection()
                    ->updateFromSelect(
                        $select,
                        ['option_dependency' => $this->setup->getTable($mainTable)]
                    );
                $this->setup->getConnection()->query($update);
            }
        }
    }

    /**
     * Update new option_type_id with mageworx_option_type_id equivalent for dependency child/parent option value
     */
    protected function convertDependencyOptionValueMageWorxIds()
    {
        $tableNames = [
            DependencyModel::TABLE_NAME                 => 'catalog_product_option_type_value',
            DependencyModel::OPTIONTEMPLATES_TABLE_NAME => 'mageworx_optiontemplates_group_option_type_value'
        ];

        foreach ($tableNames as $mainTable => $joinedTable) {

            if ($this->out($joinedTable, 'mageworx_option_type_id')) {
                continue;
            }

            $fieldMap = [
                DependencyModel::COLUMN_NAME_CHILD_MAGEWORX_OPTION_TYPE_ID  =>
                    DependencyModel::COLUMN_NAME_CHILD_OPTION_TYPE_ID,
                DependencyModel::COLUMN_NAME_PARENT_MAGEWORX_OPTION_TYPE_ID =>
                    DependencyModel::COLUMN_NAME_PARENT_OPTION_TYPE_ID
            ];

            foreach ($fieldMap as $oldColumnName => $newColumnName) {
                $select = $this->setup
                    ->getConnection()
                    ->select()
                    ->joinLeft(
                        [
                            'cpotv' => $this->setup->getTable($joinedTable)
                        ],
                        'cpotv.mageworx_option_type_id = option_value_dependency.' . $oldColumnName,
                        [
                            $newColumnName => 'option_type_id'
                        ]
                    )
                    ->where(
                        "option_value_dependency." . $oldColumnName . " IS NOT NULL"
                    );

                $update = $this->setup
                    ->getConnection()
                    ->updateFromSelect(
                        $select,
                        ['option_value_dependency' => $this->setup->getTable($mainTable)]
                    );
                $this->setup->getConnection()->query($update);
            }
        }
    }
}
