<?php

class GoDataFeed_Services_Model_Catalog_Product_Api extends Mage_Catalog_Model_Product_Api
{
	const STOCK_ITEM_MODEL = 'cataloginventory/stock_item';
	const ATTRIBUTE_SET_MODEL = 'eav/entity_attribute_set';
	const CATALOG_PRODUCT_MODEL = 'catalog/product';
	const CATALOG_CATEGORY_MODEL = 'catalog/category';
	const CONFIGURABLE_PRODUCT_MODEL = "catalog/product_type_configurable";
	const GROUPED_PRODUCT_MODEL = "catalog/product_type_grouped";

	const PRODUCT_NAME_FIELD = 'name';
	const DESCRIPTION_FIELD = 'description';
	const SHORT_DESCRIPTION_FIELD = 'short_description';
	const CATEGORY_NAME_FIELD = 'name';

	const CATEGORY_SEPARATOR = ' > ';

	public function count($filters, $stockQuantityFilterAmount, $store, $responseField)
    {
		$filteredProductsCollection = $this->getProductsFilteredByStockQuantity($filters, $stockQuantityFilterAmount, $store);

		$numberOfProducts = 0;
		if(!empty($filteredProductsCollection)) {
			$numberOfProducts = $filteredProductsCollection->getSize();
		}

		return array($responseField => $numberOfProducts);
    }

	public function extendedList(
		$filters,
		$stockQuantityFilterAmount,
		$store,
		$attributes,
		$customAttributes,
		$qtyConfig,
		$isInStockConfig,
		$attributeSetNameConfig,
		$categoryBreadCrumbConfig,
		$manufacturerNameConfig,
		$absoluteUrlConfig,
		$absoluteImageUrlConfig,
		$scrubProductName,
		$scrubDescription,
		$scrubShortDescription,
		$scrubAttributeSetName,
		$scrubCustomAttribute,
		$pageNumber,
		$productsPerPage)
    {
		$baseUrl = Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB);
		$imageBaseURL = Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_MEDIA)."catalog/product";

		$resultItems = array();

		$storeId = $this->_getStoreId($store);

		// GET PRODUCTS FOR REQUESTED STORE WITH SPECIFIED FILTERS
		$filteredProductsCollection = $filteredProductsCollection = $this->getProductsFilteredByStockQuantity($filters, $stockQuantityFilterAmount, $store);

		if(!empty($filteredProductsCollection)) {

			$filteredProducts = array();

			foreach ($filteredProductsCollection as $product) {
				$filteredProducts[] = $product;
			}

			$numberOfProducts = count($filteredProducts);
			if($numberOfProducts > 0) {

				$firstIndexToRetreive = ($pageNumber - 1) * $productsPerPage;
				$lastIndexToRetreive = $firstIndexToRetreive + $productsPerPage - 1;

				for
				(
					$indexToRetreive = $firstIndexToRetreive;
					$indexToRetreive <= $lastIndexToRetreive && $indexToRetreive < $numberOfProducts;
					$indexToRetreive++
				) {

					if(isset($filteredProducts[$indexToRetreive])) {

						$productToRetreive = $filteredProducts[$indexToRetreive];
						$productIdToRetreive = $productToRetreive->getId();
						$productToRetreive = $this->_getProduct($productIdToRetreive, $storeId, 'id');

						if(!empty($productToRetreive)) {

							$resultItem = array();

							// STANDARD ATTRIBUTES
							foreach ($productToRetreive->getTypeInstance(true)->getEditableAttributes($productToRetreive) as $attribute) {
								if ($this->_isAllowedAttribute($attribute, $attributes)) {
									$resultItem[$attribute->getAttributeCode()] = $productToRetreive->getData($attribute->getAttributeCode());
								}
							}

							// CUSTOM ATTRIBUTES
							if(!empty($customAttributes) && is_array($customAttributes)) {
								foreach($customAttributes as $customAttribute)
								{
									$attributeField = $productToRetreive->getResource()->getAttribute($customAttribute);

									//If it's an option or multiselect attribute
									if(!empty($attributeField) && $attributeField->usesSource() && $productToRetreive->getAttributeText($customAttribute)) {
										$attributeFieldValue = $productToRetreive->getAttributeText($customAttribute);
									}
									else {
										$attributeFieldValue = $productToRetreive->getData($customAttribute);
									}

									if($scrubCustomAttribute) {
										$attributeFieldValue = $this->scrubData($attributeFieldValue);
									}

									$resultItem[$customAttribute] = $attributeFieldValue;
								}
							}

							// PRODUCT NAME SCRUBBING
							if(in_array(self::PRODUCT_NAME_FIELD, $attributes) && $scrubProductName) {
								$productName = $resultItem[self::PRODUCT_NAME_FIELD];
								$resultItem[self::PRODUCT_NAME_FIELD] = $this->scrubData($productName);
							}

							// DESCRIPTION SCRUBBING
							if(in_array(self::DESCRIPTION_FIELD, $attributes) && $scrubDescription) {
								$resultItem[self::DESCRIPTION_FIELD] =
										$this->scrubData($resultItem[self::DESCRIPTION_FIELD]);
							}

							// SHORT DESCRIPTION SCRUBBING
							if(in_array(self::SHORT_DESCRIPTION_FIELD, $attributes) && $scrubShortDescription) {
								$resultItem[self::SHORT_DESCRIPTION_FIELD] =
										$this->scrubData($resultItem[self::SHORT_DESCRIPTION_FIELD]);
							}

							// IS IN STOCK & QUANTITY ATTRIBUTES
							$stockQuantityRequested = $qtyConfig[0];
							$stockStatusRequested = $isInStockConfig[0];
							if($stockQuantityRequested || $stockStatusRequested) {

								$inventoryStatus = Mage::getModel(self::STOCK_ITEM_MODEL)->loadByProduct($productToRetreive);

								if (!empty($inventoryStatus)) {

									if($stockQuantityRequested) {
										$responseField = $qtyConfig[1];
										$resultItem[$responseField] = $inventoryStatus->getQty();
									}

									if($stockStatusRequested) {
										$responseField = $isInStockConfig[1];
										$resultItem[$responseField] = $inventoryStatus->getIsInStock();
									}
								}
							}

							// ATTRIBUTE SET NAME
							$attributeSetNameRequested = $attributeSetNameConfig[0];
							if($attributeSetNameRequested) {

								$attributeSet = Mage::getModel(self::ATTRIBUTE_SET_MODEL)->load($productToRetreive->getAttributeSetId());
								if (!empty($attributeSet)) {

									$attributeSetName = $attributeSet->getAttributeSetName();
									if($scrubAttributeSetName) {
										$attributeSetName = $this->scrubData($attributeSetName);
									}

									$responseField = $attributeSetNameConfig[1];
									$resultItem[$responseField] = $attributeSetName;
								}
							}

							// CATEGORY BREADCRUMB
							$categoryBreadCrumbRequested = $categoryBreadCrumbConfig[0];
							if($categoryBreadCrumbRequested) {

								$categoryIds = Mage::getResourceSingleton(self::CATALOG_PRODUCT_MODEL)->getCategoryIds($productToRetreive);

								if (!empty($categoryIds)) {

									$categoryBreadcrumb = '';
									foreach($categoryIds as $categoryId) {

										$category = Mage::getModel(self::CATALOG_CATEGORY_MODEL)->setStoreId($storeId)->load($categoryId);

										if(!empty($category) && $category->getId()) {
											$categoryBreadcrumb .= $category->getData(self::CATEGORY_NAME_FIELD) . self::CATEGORY_SEPARATOR;
										}
									}

									$categoryBreadcrumb = preg_replace('/' . self::CATEGORY_SEPARATOR . '$/', '', $categoryBreadcrumb);

									$responseField = $categoryBreadCrumbConfig[1];
									$resultItem[$responseField] = $categoryBreadcrumb;
								}

								// MANUFACTURER NAME
								$manufacturerNameRequested = $manufacturerNameConfig[0];
								if($manufacturerNameRequested) {

									$manufacturer = $productToRetreive->getResource()->getAttribute("manufacturer");
									if (!empty($manufacturer)) {

										$manufacturerName = $manufacturer->getFrontend()->getValue($productToRetreive);
										$manufacturerNameNullValue = $manufacturerNameConfig[2];
										if(empty($manufacturerName) || $manufacturerName == $manufacturerNameNullValue) {
											$manufacturerName = '';
										}
										$responseField = $manufacturerNameConfig[1];
										$resultItem[$responseField] = $manufacturerName;
									}
								}

								// ABSOLUTE URL & IMAGE
								$absoluteUrlRequested = $absoluteUrlConfig[0];
								$absoluteImageUrlRequested = $absoluteImageUrlConfig[0];
								if($absoluteUrlRequested || $absoluteImageUrlRequested) {

									$productUrl = $productToRetreive->getUrlKey();
									$productImage = $productToRetreive->getImage();

									$noSelectionValue = $absoluteImageUrlConfig[2];

									//If it's a simple product and it's NOT visible then we are getting the URL/ImageURL from the parent (configurable/grouped) product
									if($productToRetreive->getTypeId() == 'simple' && $productToRetreive->getData("visibility") == 1)
									{
										//Checking if the product is a child of a "configurable" product
										$parentProductIds = Mage::getModel(self::CONFIGURABLE_PRODUCT_MODEL)->getParentIdsByChild($productIdToRetreive);

										//Checking if the product is a child of a "grouped" product
										if(sizeof($parentProductIds) < 1) {
											$parentProductIds = Mage::getModel(self::GROUPED_PRODUCT_MODEL)->getParentIdsByChild($productIdToRetreive);
										}

										//Setting the URL SEO to the parent URL if a parent is found
										if(isset($parentProductIds[0]))
										{
											$firstParentProduct = Mage::getModel(self::CATALOG_PRODUCT_MODEL)->load($parentProductIds[0]);
											$productUrl = $firstParentProduct->getUrlPath();

											if($productImage == "" || $productImage == $noSelectionValue) {
												$productImage = $firstParentProduct->getImage();
											}
										}
										//Blanking-out the URL/Image URL since items that are not visible and are not associated with a parent
										else
										{
											$productUrl = null;
											$productImage = null;
										}
									}

									if($absoluteUrlRequested && !empty($productUrl)) {
										$responseField = $absoluteUrlConfig[1];
										$resultItem[$responseField] = $baseUrl . $productUrl;
									}

									if($absoluteImageUrlRequested && !empty($productImage) && $productImage != $noSelectionValue) {
										$responseField = $absoluteImageUrlConfig[1];
										$resultItem[$responseField] = $imageBaseURL . $productImage;
									}
								}

							}

							$resultItems[] = $resultItem;
						}
					}
				}
			}
		}

		return $resultItems;
    }

	private function getProductsFilteredByStockQuantity($filters, $stockQuantityFilterAmount, $store) {

		$filteredProductsCollection =
			Mage::getModel(self::CATALOG_PRODUCT_MODEL)
					->getCollection()
					->joinField(
						'qty',
						'cataloginventory/stock_item',
						'qty',
						'product_id=entity_id',
						'{{table}}.stock_id=1',
						'left'
					)
					->addAttributeToFilter('qty', array('gteq' => $stockQuantityFilterAmount))
					->addStoreFilter($store);

		if (is_array($filters)) {
			try {
				foreach ($filters as $field => $value) {
					if (isset($this->_filtersMap[$field])) {
						$field = $this->_filtersMap[$field];
					}
					$filteredProductsCollection->addFieldToFilter($field, $value);
				}
			} catch (Mage_Core_Exception $e) {
				$this->_fault('filters_invalid', $e->getMessage());
			}
		}

		return $filteredProductsCollection;
	}

	//Scrubbing various unwanted characters
	private function scrubData($fieldValue)
	{
		$fieldValue = str_replace(chr(10), " ", $fieldValue);
		$fieldValue = str_replace(chr(13), " ", $fieldValue);
		$fieldValue = str_replace("\r", " ", $fieldValue);
		$fieldValue = str_replace("\n", " ", $fieldValue);
		$fieldValue = str_replace("\r\n", " ", $fieldValue);
		$fieldValue = str_replace("\t", "    ", $fieldValue);
		return $fieldValue;
	}
}