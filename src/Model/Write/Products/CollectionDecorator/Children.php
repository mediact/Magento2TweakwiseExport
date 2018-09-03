<?php
/**
 * @author Emico <info@emico.nl>
 * @copyright (c) Emico B.V. 2017
 */

namespace Emico\TweakwiseExport\Model\Write\Products\CollectionDecorator;

use Emico\TweakwiseExport\Model\Write\EavIteratorFactory;
use Emico\TweakwiseExport\Model\Write\Products\Collection;
use Emico\TweakwiseExport\Model\Write\Products\CollectionFactory;
use Emico\TweakwiseExport\Model\Write\Products\ExportEntity;
use Emico\TweakwiseExport\Model\Write\Products\ExportEntityChildFactory;
use Emico\TweakwiseExport\Model\Write\Products\IteratorInitializer;
use Magento\Bundle\Model\Product\Type as Bundle;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Type as ProductType;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Enterprise\Model\ProductMetadata;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Model\ResourceModel\Db\Context as DbContext;
use Magento\GroupedProduct\Model\Product\Type\Grouped;
use Magento\GroupedProduct\Model\ResourceModel\Product\Link;

class Children extends AbstractDecorator
{
    /**
     * @var ProductType
     */
    private $productType;

    /**
     * @var EavIteratorFactory
     */
    private $eavIteratorFactory;

    /**
     * @var ExportEntityChildFactory
     */
    private $entityChildFactory;

    /**
     * @var Collection
     */
    protected $childEntities;

    /**
     * @var IteratorInitializer
     */
    private $iteratorInitializer;

    /**
     * @var CollectionFactory
     */
    private $collectionFactory;

    /**
     * @var ProductMetadataInterface
     */
    private $appInfo;

    /**
     * ChildId constructor.
     *
     * @param DbContext $dbContext
     * @param ProductType $productType
     * @param EavIteratorFactory $eavIteratorFactory
     * @param IteratorInitializer $iteratorInitializer
     * @param ExportEntityChildFactory $entityChildFactory
     * @param CollectionFactory $collectionFactory
     * @param ProductMetadataInterface $appInfo
     */
    public function __construct(
        DbContext $dbContext,
        ProductType $productType,
        EavIteratorFactory $eavIteratorFactory,
        IteratorInitializer $iteratorInitializer,
        ExportEntityChildFactory $entityChildFactory,
        CollectionFactory $collectionFactory,
        ProductMetadataInterface $appInfo
    )
    {
        parent::__construct($dbContext);
        $this->productType = $productType;
        $this->eavIteratorFactory = $eavIteratorFactory;
        $this->entityChildFactory = $entityChildFactory;
        $this->iteratorInitializer = $iteratorInitializer;
        $this->collectionFactory = $collectionFactory;
        $this->appInfo = $appInfo;
    }

    /**
     * {@inheritdoc}
     */
    public function decorate(Collection $collection)
    {
        $this->childEntities = $this->collectionFactory->create(['storeId' => $collection->getStoreId()]);
        $this->createChildEntities($collection);
        $this->loadChildAttributes($collection->getStoreId());
    }

    /**
     * @param Collection $collection
     */
    private function createChildEntities(Collection $collection)
    {
        foreach ($this->getGroupedEntities($collection) as $typeId => $group) {
            // Create fake product type to trick type factory to use getTypeId
            /** @var Product $fakeProduct */
            $fakeProduct = new DataObject(['type_id' => $typeId]);
            $type = $this->productType->factory($fakeProduct);
            $isComposite = $type->isComposite($fakeProduct);
            foreach ($group as $entity) {
                $entity->setIsComposite($isComposite);
                $entity->setChildren([]);
            }

            $parentIds = array_keys($group);
            if ($type instanceof Bundle) {
                $this->addBundleChildren($collection, $parentIds);
            } elseif ($type instanceof Grouped) {
                $this->addLinkChildren($collection, $parentIds, Link::LINK_TYPE_GROUPED);
            } elseif ($type instanceof Configurable) {
                $this->addConfigurableChildren($collection, $parentIds);
            } else {
                foreach ($parentIds as $parentId) {
                    foreach ($type->getChildrenIds($parentId, false) as $childId) {
                        $this->addChild($collection, (int) $parentId, (int) $childId);
                    }
                }
            }
        }
    }

    /**
     * @param Collection $collection
     * @return ExportEntity[][]
     */
    private function getGroupedEntities(Collection $collection): array
    {
        $groups = [];
        foreach ($collection as $entity) {
            $typeId = $entity->getAttribute('type_id', false);
            if (!isset($groups[$typeId])) {
                $groups[$typeId] = [];
            }

            $groups[$typeId][$entity->getId()] = $entity;
        }
        return $groups;
    }

    /**
     * @param Collection $collection
     * @param int[] $parentIds
     */
    private function addBundleChildren(Collection $collection, array $parentIds)
    {
        $connection = $this->getConnection();
        $select = $connection->select();
        $select->from(['bundle_selection' => $this->getTableName('catalog_product_bundle_selection')]);

        if ($this->isEnterprise()) {
            $select->columns(['parent_product_id'])->join(
                ['product_table' => $this->getTableName('catalog_product_entity')],
                'bundle_selection.product_id = product_table.row_id',
                ['product_id' => 'row_id']
            );
        } else {
            $select->columns(['product_id', 'parent_product_id']);
        }
        $select->where('parent_product_id IN (?)', $parentIds);
        $query = $select->query();
        while ($row = $query->fetch()) {
            $this->addChild($collection, (int) $row['parent_product_id'], (int) $row['product_id']);
        }
    }

    /**
     * @param Collection $collection
     * @param int[] $parentIds
     * @param int $typeId
     */
    private function addLinkChildren(Collection $collection, array $parentIds, $typeId)
    {
        $connection = $this->getConnection();
        $select = $connection->select();
        $select->from(['link_table' => $this->getTableName('catalog_product_link')]);

        if ($this->isEnterprise()) {
            $select->columns(['product_id']);
            $select->join(
                ['product_table' => $this->getTableName('catalog_product_entity')],
                'link_table.linked_product_id = product_table.row_id',
                ['linked_product_id' => 'row_id']
            );
        } else {
            $select->columns(['linked_product_id', 'product_id']);
        }
        $select
            ->where('link_type_id = ?', $typeId)
            ->where('product_id IN (?)', $parentIds);

        $query = $select->query();
        while ($row = $query->fetch()) {
            $this->addChild($collection, (int) $row['product_id'], (int) $row['linked_product_id']);
        }
    }

    /**
     * @param Collection $collection
     * @param int[] $parentIds
     */
    private function addConfigurableChildren(Collection $collection, array $parentIds)
    {
        $connection = $this->getConnection();
        $select = $connection->select();
        $select->from(['link_table' => $this->getTableName('catalog_product_super_link')]);

        if ($this->isEnterprise()) {
            $select->columns(['parent_id'])->join(
                ['product_table' => $this->getTableName('catalog_product_entity')],
                'link_table.product_id = product_table.row_id',
                ['product_id' => 'row_id']
            );
        } else {
            $select->columns(['product_id', 'parent_id']);
        }
        $select->where('parent_id IN (?)', $parentIds);

        $query = $select->query();
        while ($row = $query->fetch()) {
            $this->addChild($collection, (int) $row['parent_id'], (int) $row['product_id']);
        }
    }

    /**
     * @param Collection $collection
     * @param int $parentId
     * @param int $childId
     */
    private function addChild(Collection $collection, int $parentId, int $childId)
    {
        if (!$this->childEntities->has($childId)) {
            $child = $this->entityChildFactory->create(['storeId' => $collection->getStoreId(), 'data' => ['entity_id' => $childId]]);
            $this->childEntities->add($child);
        } else {
            $child = $this->childEntities->get($childId);
        }

        $collection->get($parentId)->addChild($child);
    }

    /**
     * Load child attribute data
     */
    private function loadChildAttributes(int $storeId)
    {
        if ($this->childEntities->count() === 0) {
            return;
        }

        $iterator = $this->eavIteratorFactory->create(['entityCode' => Product::ENTITY, 'attributes' => []]);
        $iterator->setEntityIds($this->childEntities->getIds());
        $iterator->setStoreId($storeId);
        $this->iteratorInitializer->initializeAttributes($iterator);

        foreach ($iterator as $childData) {
            $childId = (int) $childData['entity_id'];
            $childEntity = $this->childEntities->get($childId);
            $childEntity->setFromArray($childData);
        }
    }

    /**
     * @return bool
     */
    private function isEnterprise()
    {
        return $this->appInfo->getEdition() === ProductMetadata::EDITION_NAME;
    }
}