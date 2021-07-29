<?php

class BrandSource_Blog_Model_Mysql4_Blog_Collection extends AW_Blog_Model_Mysql4_Blog_Collection {

    /**
     * Add Filter by store
     *
     * CUSTOM:
     * * Modified - removed single store mode 4-3-17 by rgrow
     *
     * NOTES:
     * * Removed previous performance optimization:
     *      * Modified - added ->group('main_table.post_id') After ->where('store_table.store_id in (?)', array(0, $store))- 9-12-13 by rgrow
     *      * Was breaking blog/index pager and backend posts found when using Store View filtering on the grid.
     *      * Not working maybe due to collection being cached in AW_Blog_Block_Blog::_prepareCollection() or collection being modified later
     *
     * @param int|Mage_Core_Model_Store $store
     *
     * @return Mage_Cms_Model_Mysql4_Page_Collection
     */
    public function addStoreFilter($store = null)
    {
        if ($store === null) {
            $store = Mage::app()->getStore()->getId();
        }
        // if (!Mage::app()->isSingleStoreMode()) {
        if ($store instanceof Mage_Core_Model_Store) {
            $store = array($store->getId());
        }

        $this
            ->getSelect()
            ->joinLeft(
                array('store_table' => $this->getTable('store')),
                'main_table.post_id = store_table.post_id',
                array()
            )
            ->where('store_table.store_id in (?)', array(0, $store));

        // CUSTOMIZATION REMOVED:
        // ->group('main_table.post_id') // CUSTOM

        // }
        return $this;
    }

    // Like addPresentFilter() but uses the store timezone rather than the server timezone
    // CUSTOM: schedule and show by date ignoring the time
    public function addPresentInStoreFilter($storeId=0)
    {
        $storeDate = Mage::app()->getLocale()->storeDate($storeId, null, true)->get('YYYY-MM-dd');
        $storeEndOfDay = date('Y-m-d H:i:s', strtotime($storeDate . ' +1 days') - 1);
        $storeEndOfDayUtc = Mage::getSingleton('core/date')->gmtDate(null, strtotime($storeEndOfDay));
        $this->getSelect()->where('main_table.created_time<=?', $storeEndOfDayUtc);
        return $this;
    }

    public function addTodayFilter($storeId=0)
    {
        $storeDate = Mage::app()->getLocale()->storeDate($storeId, null, true)->get('YYYY-MM-dd');
        $storeEndOfDay = date('Y-m-d H:i:s', strtotime($storeDate . ' +1 days') - 1);
        $storeEndOfDayUtc = Mage::getSingleton('core/date')->gmtDate(null, strtotime($storeEndOfDay));
        $storeStartOfDayUtc = Mage::getSingleton('core/date')->gmtDate(null, strtotime($storeDate));
        $this->getSelect()->where('main_table.created_time>=?', $storeStartOfDayUtc);
        $this->getSelect()->where('main_table.created_time<=?', $storeEndOfDayUtc);
        return $this;
    }

    // NOTE: must combine with addPresentInStoreFilter(), so future
    // scheduled posts will not be returned
    public function addYearMonthFilter($year=1900, $month=1, $storeId=0, $useUTC=false) {
        $storeStart = Mage::app()->getLocale()->storeDate($storeId, strtotime($year . '-' . $month . '-1'), false)->getTimestamp();
        $storeStartTime = $useUTC ? Mage::getSingleton('core/date')->gmtDate(null, $storeStart) : Mage::getSingleton('core/date')->date(null, $storeStart);

        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        $storeStop = Mage::app()->getLocale()->storeDate($storeId, strtotime($year . '-' . $month . '-' . $daysInMonth), false)->getTimestamp();
        $storeStopTime = $useUTC ? Mage::getSingleton('core/date')->gmtDate(null, $storeStop) : Mage::getSingleton('core/date')->date(null, $storeStop);

        $this->getSelect()->where('main_table.created_time>=?', $storeStartTime);
        $this->getSelect()->where('main_table.created_time<=?', $storeStopTime);
        return $this;
    }

    // Based on joinComments()
    public function joinLikesCount() {
        $select = new Zend_Db_Select($connection = Mage::getSingleton('core/resource')->getConnection('read'));
        $select
            ->from(
                Mage::getSingleton('core/resource')->getTableName('brandsource_blog/vote'),
                array('post_id', 'likes' => new Zend_Db_Expr('COUNT(IF(direction = ' . BrandSource_Blog_Model_Vote::VOTE_DIR_LIKE . ', post_id, NULL))'))
            )
            ->group('post_id');

        $this
            ->getSelect()
            ->joinLeft(
                array('likes_select' => $select),
                'main_table.post_id = likes_select.post_id',
                'likes'
            );
        return $this;
    }

    public function joinDislikesCount() {
        $select = new Zend_Db_Select($connection = Mage::getSingleton('core/resource')->getConnection('read'));
        $select
            ->from(
                Mage::getSingleton('core/resource')->getTableName('brandsource_blog/vote'),
                array('post_id', 'dislikes' => new Zend_Db_Expr('COUNT(IF(direction = ' . BrandSource_Blog_Model_Vote::VOTE_DIR_DISLIKE . ', post_id, NULL))'))
            )
            ->group('post_id');

        $this
            ->getSelect()
            ->joinLeft(
                array('dislikes_select' => $select),
                'main_table.post_id = dislikes_select.post_id',
                'dislikes'
            );
        return $this;
    }

    // Decouple from collection, added back in separate query in api data.
    /*
    public function joinBrand() {
        $this->getSelect()
            ->joinLeft(
                array('post_brand' => $this->getTable('brandsource_blog/post_brand')),
                'main_table.post_id = post_brand.post_id', array()
            )
            ->joinLeft(
                array('brand' => $this->getTable('brandsource_blog/brand')),
                'post_brand.brand_id = brand.brand_id', array('brand' => 'brand.title', 'brand_identifier' => 'brand.identifier')
            )
            ->columns("GROUP_CONCAT(CONCAT_WS(',', brand.title, brand.identifier) SEPARATOR '|') AS brands")
            ->group("main_table.post_id");

        return $this;
    }
    */

    // Category was originally a multiselect so some old Posts exist with 2 Categories
    public function joinCategory() {
        $select = new Zend_Db_Select($connection = Mage::getSingleton('core/resource')->getConnection('read'));
        $select
            ->from(
                Mage::getSingleton('core/resource')->getTableName('blog/post_cat'),
                array('cat_id', 'post_id')
            )
            ->group('post_id');

        $this->getSelect()
            ->joinLeft(
                array('post_cat' => $select),
                'main_table.post_id = post_cat.post_id', array()
            )
            ->joinLeft(
                array('cat' => $this->getTable('blog/cat')),
                'post_cat.cat_id = cat.cat_id', array('category' => 'cat.title', 'category_identifier' => 'cat.identifier')
            );

        return $this;
    }

    public function addIdsFilter($postIds=array()) {
        $this->getSelect()->where('main_table.post_id IN (?)', $postIds);
        return $this;
    }

    // Based on addCatFilter()
    public function addBrandFilter($brandId)
    {
        $this
            ->getSelect()
            ->join(
                array('brand_table' => $this->getTable('brandsource_blog/post_brand')), 'main_table.post_id = brand_table.post_id', array()
            )
            ->where('brand_table.brand_id = ?', $brandId)
        ;

        return $this;
    }

    /**
     * CUSTOM
     * - make tags case sensitive since the API tag interface is all name based
     */
    public function addTagFilter($tag)
    {
        if ($tag = trim($tag)) {
            $whereString = sprintf(
                "BINARY main_table.tags = %s OR main_table.tags LIKE BINARY %s OR main_table.tags LIKE BINARY %s OR main_table.tags LIKE BINARY %s",
                $this->getConnection()->quote($tag), $this->getConnection()->quote($tag . ',%'),
                $this->getConnection()->quote('%,' . $tag), $this->getConnection()->quote('%,' . $tag . ',%')
            );
            $this->getSelect()->where($whereString);
        }
        return $this;
    }
}
