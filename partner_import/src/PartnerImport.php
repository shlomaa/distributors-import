<?php

namespace Drupal\partner_import;

use Drupal\commerce_stock_local\Entity\StockLocation;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\Entity\Node;
use Drupal\commerce_store\Entity\Store;
use Drupal\taxonomy\Entity\Term;
use Drupal\commerce_product\Entity\ProductAttributeValue;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_price\Price;

/**
 * Partner import service.
 */
class PartnerImport {

  /**
   * Used for loading and creating Drupal user objects.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current user service.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The stock service manager.
   *
   * @var \Drupal\commerce_stock\StockServiceManager
   */
  protected $stockServiceManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager, AccountInterface $user) {
    $this->entityTypeManager = $entityTypeManager;
    $this->currentUser = $user;
    $this->stockServiceManager = \Drupal::service('commerce_stock.service_manager');
  }


  /**
   * Import partner stores/products according to his attached XML file.
   */
  public function importPartner(Node $partner) {
    $start = time();
    $fetchUrl = $partner->get('field_import_url')->value;
    $xml_data = $this->getXML($fetchUrl);
    $now = DrupalDateTime::createFromTimestamp(time());
    $now->setTimezone(new \DateTimeZone('UTC'));

    if (!empty($xml_data)) {
      \Drupal::logger('partner_import')->info(t($partner->label() . ': Данные получены.'));
    }

    $statistics = [
      'regions_count' => 0,
      'duration' => 0,
      'count' => 0,
      'updated' => 0,
      'date' => $now->format('Y-m-d\TH:i:s'),
      'errors' => [],
      'created' => 0,
      'deleted' => 0,
    ];

    $parse_errors = [];
    $result = $this->parseXML($xml_data, $parse_errors);

    if (!empty($result)) {
      \Drupal::logger('partner_import')->info(t($partner->label() . ': Данные распознаны.'));
    }
    else {
      \Drupal::logger('partner_import')->info(t($partner->label() . ': Не удалось распознать данные.'));
    }

    if (count($parse_errors)) {
      $statistics['errors'] = array_merge($statistics['errors'], $parse_errors);
    }

    $data = $this->convertData($result, $partner,$statistics);

    if (!empty($data)) {
      \Drupal::logger('partner_import')->info(t($partner->label() . ': Данные обработаны.'));
    }
    else {
      \Drupal::logger('partner_import')->info(t($partner->label() . ': Не удалось обработать данные.'));
    }

    $sizes = $this->getProductSizes();

    // Creation of stores and stocks.
    foreach ($data['stores'] as $region_code => $store_data) {
      // Find existing store.
      $existing_store = $this->findStore($store_data['id']);

      // Find existing stock locations.
      $existing_stocks = $existing_store ? $this->getStoreStockLocations($existing_store) : [];

      // Create stock locations if needed.
      $available_stocks = [];
      if (!empty($store_data['stocks'])) {
        foreach ($store_data['stocks'] as $location) {
          if (isset($existing_stocks[$location])) {
            $available_stocks[] = ['target_id' => $existing_stocks[$location]->id()];
          }
          else {
            $stock = StockLocation::create([
              'type' => 'default',
              'name' => $data['stocks'][$location],
            ]);
            $stock->set('field_stock_unique_id', $location);
            $stock->save();
            $available_stocks[] = ['target_id' => $stock->id()];
          }
        }
      }

      // Create store if needed.
      if (!$existing_store) {
        $address = [
          'country_code' => 'RU',
          'address_line1' => $store_data['city'],
          'locality' => $store_data['city'],
          'administrative_area' => '',
          'postal_code' => '',
        ];
        $store = Store::create([
          'type' => 'online',
          'name' => $store_data['title'],
          'default_currency' => 'RUB',
          'address' => $address,
          'timezone' => 'Europe/Moscow',
          'field_store_unique_id' => $store_data['id'],
        ]);
      }
      else {
        $store = $existing_store;
      }

      // Deactivate all products of this store.
      $products_before = [];
      $commerce_products = $this->findCommerceProducts($store);
      foreach ($commerce_products as $commerce_product) {
        $commerce_product->setUnpublished();
        $commerce_product->save();
        $products_before[] = $commerce_product->id();
      }

      // Find region in region ISO taxonomy.
      $region = $this->getRegionByISO($region_code);
      if (!$region) {
        $statistics['errors'][] = t('Неверный формат региона ' . $region_code);
        $processed_products = [];
      }
      else {
        $store->set('field_region', ['target_id' => $region->id()]);
        $store->set('field_stock_allocation_location', current($available_stocks));
        $store->set('field_available_stock_locations', $available_stocks);
        $store->set('field_related_partner', ['target_id' => $partner->id()]);
        $store->save();

        // Import products.
        $product_variations = isset($data['product_variations'][$region_code]) ? $data['product_variations'][$region_code] : [];
        $processed_products = $this->importProducts($product_variations, $sizes, $data, $store, $statistics);
      }
      $statistics['count'] += count($processed_products);
      $statistics['deleted'] += count(array_diff($products_before, array_keys($processed_products)));
      $statistics['created'] += count(array_diff(array_keys($processed_products), $products_before));
      $statistics['updated'] += count(array_intersect(array_keys($processed_products), $products_before));
      if (count($processed_products) > 0) {
        $statistics['regions_count']++;
      }
    }
    $statistics['duration'] = time() - $start;

    // Save statistics.
    foreach ($statistics as $field => $val) {
      $partner->set('field_import_' . $field, $val);
    }
    $partner->save();

    \Drupal::logger('partner_import')->info(t($partner->label() . ': Импорт окончен.'));

  }

  /**
   * Make all products of this partner inactive in every store.
   */
  public function disablePartner(Node $partner, $message = '') {
    $start = time();
    $statistics = [
      'regions_count' => 0,
      'duration' => 0,
      'count' => 0,
      'updated' => 0,
      'date' => date('Y-m-d\TH:i:s', time()),
      'errors' => [],
      'created' => 0,
      'deleted' => 0,
    ];
    if ($message) {
      $statistics['errors'][] = $message;
    }
    $store_query = $this->entityTypeManager->getStorage('commerce_store')->getQuery();
    $store_query->condition('type', 'online');
    $store_query->condition('field_related_partner', $partner->id());
    $store_query->accessCheck(FALSE);
    $existing_stores = $store_query->execute();

    $stores = !empty($existing_stores) ? Store::loadMultiple($existing_stores) : [];

    if (!empty($stores)) {
      $cart_manager = \Drupal::service('commerce_cart.cart_manager');

      foreach ($stores as $store) {
        // Deactivate all products of this store.
        $commerce_products = $this->findCommerceProducts($store);
        foreach ($commerce_products as $commerce_product) {
          $commerce_product->setUnpublished();
          $commerce_product->save();
          $statistics['deleted']++;
        }

        // Remove deactivated products from carts
        if (!empty($commerce_products)) {
          $commerce_products_ids = array_keys($commerce_products);

          $query = $this->entityTypeManager->getStorage('commerce_order')->getQuery()
            ->condition('state', 'draft')
            ->condition('cart', TRUE)
            ->condition('store_id', $store->id())
            ->sort('order_id', 'DESC')
            ->accessCheck(FALSE);
          $cart_ids = $query->execute();
          $cart_orders = $this->entityTypeManager->getStorage('commerce_order')->loadMultiple($cart_ids);

          foreach ($cart_orders as $order) {
            $items = $order->getItems();
            $deleted_item_has_related_kit = [];
            foreach ($items as $item) {
              if (!$item->hasPurchasedEntity()) {
                continue;
              }
              if (!empty($item->getPurchasedEntity())) {
                $product_id = $item->getPurchasedEntity()->getProductId();
                if (in_array($product_id, $commerce_products_ids)) {
                  if ($item->hasField('field_related_kit') && !$item->get('field_related_kit')->isEmpty()) {
                    $deleted_item_has_related_kit[$item->get('field_related_kit')->target_id] = TRUE;
                  }
                  $cart_manager->removeOrderItem($order, $item);
                }
              }
            }

            // check order item from kit complex with zero price
            $items = $order->getItems();
            foreach ($items as $item) {
              $related_kit = $item->hasField('field_related_kit') && !$item->get('field_related_kit')->isEmpty()
                ? $item->get('field_related_kit')->target_id
                : NULL;
              $unit_price = floatval($item->getUnitPrice()->getNumber());
              if ($related_kit && !empty($deleted_item_has_related_kit[$related_kit]) && !$unit_price > 0) {
                $purchasedEntity = $item->getPurchasedEntity();
                if (!empty($purchasedEntity)) {
                  $product_id = $purchasedEntity->getProductId();
                  if (in_array($product_id, $commerce_products_ids)) {
                    if ($item->hasField('field_related_kit') && !$item->get('field_related_kit')->isEmpty()) {
                      $deleted_item_has_related_kit[$item->get('field_related_kit')->target_id] = TRUE;
                    }
                    $cart_manager->removeOrderItem($order, $item);
                  }
                }
              }
            }
          }
        }
      }
    }
    $statistics['duration'] = time() - $start;

    // Save statistics.
    foreach ($statistics as $field => $val) {
      $partner->set('field_import_' . $field, $val);
    }
    $partner->setUnpublished();
    $partner->save();
  }

  /**
   * Get list of values of commerce product attribute "Size".
   */
  public function getProductSizes() {
    $result = [];
    $productAttributeStorage = \Drupal::entityTypeManager()
      ->getStorage('commerce_product_attribute_value');
    $attribute_values = $productAttributeStorage->loadMultipleByAttribute('size');
    foreach ($attribute_values as $attribute) {
      $result[$attribute->getName()] = $attribute;
    }

    return $result;
  }

  /**
   * Import products based on passed parsed data.
   */
  public function importProducts($product_variations, &$sizes, $data, Store $store, &$statistics) {
    $stock_locations = $this->getStoreStockLocations($store);
    $processed_products = [];
    foreach ($product_variations as $product_id => $variations) {
      $sg_product = Node::load($product_id);
      $sg_variations = $this->getSgProductVariations($sg_product);
      $existing_variations = [];
      $new_variations = [];

      // Find or create commerce product.
      $commerce_product = $this->findCommerceProduct($product_id, $store);
      if (empty($commerce_product)) {
        $commerce_product = Product::create([
          'type' => 'default',
          'title' => $sg_product->label(),
          'stores' => [$store],
          'field_related_product' => ['target_id' => $product_id],
        ]);
      }
      else {
        $existing_variations_raw = $commerce_product->getVariations();
        foreach ($existing_variations_raw as $commerce_variation) {
          $existing_variations[$commerce_variation->getSku()] = $commerce_variation;
        }
      }

      $active_existing_variations = [];
      // Go through the variations that partner sent.
      foreach ($variations as $sku => $variation) {
        // Check if partner sends proper sku that is known in the system.
        if (!isset($sg_variations[$sku])) {
          continue;
        }
        if (!isset($variation['price']) || !isset($variation['count'])) {
          continue;
        }
        // Check existing attributes.
        $attribute = isset($sizes[$variation['size']]) ? $sizes[$variation['size']] : FALSE;
        if (!$attribute) {
          $attribute = ProductAttributeValue::create([
            'attribute' => 'size',
            'name' => $variation['size'],
          ]);
          $attribute->save();
          $sizes[$variation['size']] = $attribute;
        }

        // Check existing variations.
        if (isset($existing_variations[$variation['sku']])) {
          $commerce_variation = $existing_variations[$variation['sku']];
          $active_existing_variations[] = $existing_variations[$variation['sku']]->id();
        }
        else {
          $commerce_variation = ProductVariation::create([
            'type' => 'default',
            'sku' => $variation['sku'],
          ]);
        }
        $commerce_variation->setPrice(new Price($variation['price'], 'RUB'));
        $commerce_variation->setPublished();
        $commerce_variation->set('attribute_size', $attribute);
        $commerce_variation->set('field_related_product_variation', ['target_id' => $sg_variations[$sku]->id()]);
        $commerce_variation->save();
        $new_variations[] = $commerce_variation;

        // Update stock locations amount.
        foreach ($variation['count'] as $stock_id => $qty) {
          if (!isset($stock_locations[$stock_id])) {
            continue;
          }
          if (!is_numeric($qty) || $qty < 0) {
            $this->setStockLevel($commerce_variation, $stock_locations[$stock_id], 0);
            $statistics['errors'][] = t('Неверный формат остатков: "' . $qty . '" для SKU ' . $sku);
          }
          else {
            $this->setStockLevel($commerce_variation, $stock_locations[$stock_id], $qty);
          }
        }
      }

      // If there is no variations suitable from partner, unpublish this product.
      // Otherwise publish it and attach variations.
      if (!empty($new_variations)) {
        $commerce_product->set('variations', $new_variations);
        $commerce_product->setPublished();
        $commerce_product->save();
        $processed_products[$commerce_product->id()] = $commerce_product->id();
      }
      else {
        $commerce_product->set('variations', []);
        $commerce_product->setUnpublished();
        $commerce_product->save();
      }

      /* Delete unused variations */
      foreach ($existing_variations_raw as $existing_variation) {
        if (!in_array($existing_variation->id(), $active_existing_variations)) {
          $existing_variation->delete();
        }
      }
    }

    return $processed_products;
  }

  /**
   * Set stock level for given commerce product variation in exact stock location.
   */
  public function setStockLevel(ProductVariation $product_variation, StockLocation $location, $qty) {
    $source_location = $location->id();
    $source_zone = '';

    $stock_checker = $this->stockServiceManager->getService($product_variation)->getStockChecker();
    $current_qty = $stock_checker->getTotalStockLevel($product_variation, [$location]);

    $diff = $qty - $current_qty;
    if ($diff > 0) {
      $transaction_note = t('Partner import: add stock level');
      $this->stockServiceManager->receiveStock($product_variation, $source_location, $source_zone, $diff, NULL, $currency_code = NULL, $transaction_note);
    }
    elseif ($diff < 0) {
      $transaction_note = t('Partner import: remove stock level');
      $this->stockServiceManager->sellStock($product_variation, $source_location, $source_zone, abs($diff), NULL, $currency_code = NULL, NULL, NULL, $transaction_note);
    }

  }

  /**
   * Get commerce product based on node ID of product definition in exact store.
   */
  public function findCommerceProduct($product_id, Store $store) {
    $query = $this->entityTypeManager->getStorage('commerce_product')->getQuery();
    $query->condition('type', 'default');
    $query->condition('field_related_product', $product_id);
    $query->condition('stores', [$store->id()], 'IN');
    $query->accessCheck(FALSE);
    $commerce_product = $query->execute();

    return $commerce_product ? Product::load(current($commerce_product)) : NULL;
  }

  /**
   * Get all commerce products of the store.
   */
  public function findCommerceProducts(Store $store) {
    $query = $this->entityTypeManager->getStorage('commerce_product')->getQuery();
    $query->condition('type', 'default');
    $query->condition('stores', [$store->id()], 'IN');
    $query->accessCheck(FALSE);
    $commerce_products = $query->execute();

    return $commerce_products ? Product::loadMultiple($commerce_products) : [];
  }

  /**
   * Get store by unique ID (import ID).
   */
  public function findStore($unique_id) {
    $store_query = $this->entityTypeManager->getStorage('commerce_store')->getQuery();
    $store_query->condition('type', 'online');
    $store_query->condition('field_store_unique_id', $unique_id);
    $store_query->accessCheck(FALSE);
    $existing_store = $store_query->execute();

    return !empty($existing_store) ? Store::load(current($existing_store)) : FALSE;
  }

  /**
   * Get all stock locations of the given store.
   */
  public function getStoreStockLocations(Store $store) {
    $existing_stocks_raw = $store->get('field_available_stock_locations')->referencedEntities();
    $existing_stocks = [];
    if (!empty($existing_stocks_raw)) {
      foreach ($existing_stocks_raw as $stock) {
        $stock_id = $stock->get('field_stock_unique_id')->value;
        $existing_stocks[$stock_id] = $stock;
      }
    }

    return $existing_stocks;
  }

  /**
   * Get country term by ISO code of the russian regions.
   */
  public function getRegionByISO($iso_code) {
    $query = \Drupal::entityQuery('taxonomy_term')
      ->condition('field_iso', $iso_code);
    $tids = $query->execute();
    $region = Term::loadMultiple($tids);

    return !empty($region) ? current($region) : FALSE;
  }

  /**
   * Get sg products variations definitions by sg product definition.
   */
  public function getSgProductVariations(Node $sg_product) {
    $sg_variations_raw = !$sg_product->get('field_variations')->isEmpty()
      ? $sg_product->get('field_variations')->referencedEntities()
      : [];
    $sg_variations = [];
    foreach ($sg_variations_raw as $item) {
      $sku = $item->get('field_product_sku')->value;
      $sg_variations[$sku] = $item;
    }

    return $sg_variations;
  }

  /**
   * Download XML.
   */
  public function getXML($url) {
    $xml_data = '';
    try {
      $response = \Drupal::httpClient()->get($url);
      if ($response->getStatusCode() === 200) {
        $xml_data = (string) $response->getBody();
      }
    }
    catch (\Exception $e) {
      // Nothing.
    }

    if (strpos($xml_data, '<?xml') === FALSE) {
      $xml_data = '';
    }

    return $xml_data;
  }

  /**
   * Parse partner XML.
   */
  public function parseXML($xml_data, &$errors) {
    $result = [];
    if (empty($xml_data)) {
      $errors[] = t('Не удалось получить XML.');

      return [];
    }

    try {
      $xml = new \SimpleXMLElement($xml_data);
    }
    catch (\Exception $e) {
      $errors[] = t('XML формат поврежден: ' . $e->getMessage());

      return [];
    }

    if (!$xml instanceof \SimpleXMLElement || empty($xml->xpath("/products/product"))) {
      $errors[] = t('XML формат поврежден');

      return [];
    }

    foreach ($xml->xpath("/products/product") as $product) {
      $id = (string) $product->xpath('id')[0];
      if (empty($id)) {
        $errors[] = t('SKU не надено: отсутствует обязательный параметр id');
        continue;
      }
      $result[$id] = [
        'title' => (string) $product->xpath('title')[0],
        'stocks' => [],
      ];
      foreach ($product->xpath("regions/region") as $region) {
        $region_title = (string) $region->xpath('code')[0];
        if (empty($region_title)) {
          $errors[] = t($id . ' | Код региона не найден: отсутствует обязательный параметр code');
          continue;
        }
        // Some cities are present as independent region code.
        // We should join it with whole region,
        if (in_array($region_title, ['RU-MOS', 'RU-MOW'])) {
          $region_title = ['RU-MOS', 'RU-MOW'];
        }
        elseif (in_array($region_title, ['RU-LEN', 'RU-SPE'])) {
          $region_title = ['RU-LEN', 'RU-SPE'];
        }
        else {
          $region_title = [$region_title];
        }
        foreach ($region->xpath("stocks/stock") as $stock) {
          $stock_data = [];
          foreach (['stock_id', 'city', 'address'] as $field) {
            $stock_data[$field] = (string) $stock->xpath($field)[0];
          }
          foreach (['available', 'active', 'pickup'] as $field) {
            $stock_data[$field] = (int) $stock->xpath($field)[0];
          }
          $stock_data['price'] = $this->parsePrice($stock->xpath('price')[0]);
          foreach ($region_title as $region_title_part) {
            $result[$id]['stocks'][$region_title_part][] = $stock_data;
          }
        }
      }
    }

    return $result;
  }

  /**
   * Parse price.
   */
  public function parsePrice($price): float {
    return floatval(str_replace(',', '.', strval($price)));
  }

  /**
   * Helper function to convert partner xml data into the structured array.
   */
  private function convertData($result, $partner, &$statistics) {
    $data = [];
    $partner_id = $partner->get('field_partner_unique_id')->value;

    if (empty($partner_id)) {
      $statistics['errors'][] = t('Отсутствует уникальный идентификатор партнера в Drupal');
      return $data;
    }

    if (count($result) === 0) {
      $statistics['errors'][] = t('Невырный формат XML: продукты не найдены');
    }

    foreach ($result as $sku => $item) {
      $size = '';
      $product = FALSE;
      $query = \Drupal::entityQuery('node')
        ->condition('status', 1)
        ->condition('type', 'sg_variation')
        ->condition('field_product_sku', $sku);

      $product_variation = $query->execute();
      $product_variation = !empty($product_variation) ? current(Node::loadMultiple($product_variation)) : FALSE;
      if ($product_variation) {
        $size = !$product_variation->get('field_product_size')->isEmpty() ? $product_variation->get('field_product_size')->value : '';
        $query = \Drupal::entityQuery('node')
          ->condition('status', 1)
          ->condition('type', 'sg_product')
          ->condition('field_variations', $product_variation->id());
        $product = $query->execute();
        $product = !empty($product) ? current(Node::loadMultiple($product)) : FALSE;
      }
      if (!empty($product)) {
        $data['products'][$product->id()] = $product->label();
      }
      else {
        continue;
      }
      if (empty($item['stocks'])) {
        $statistics['errors'][] = t('Для SKU ' . $sku . ' не удалось распознать xml');
        continue;
      }
      foreach ($item['stocks'] as $region_code => $stocks) {
        $data['stores'][$region_code]['title'] = $partner->label() . ' ' . $region_code;
        $data['stores'][$region_code]['id'] = $partner_id . '_' . $region_code;
        $new_sku = $partner_id . '_' . $region_code . '_' . $sku;

        if (!empty($size)) {
          $data['product_variations'][$region_code][$product->id()][$sku] = [
            'size' => $size,
            'sku' => $new_sku,
          ];
        }
        foreach ($stocks as $stock) {
          $error_on_stick = FALSE;
          if (empty($stock['city'])) {
            $statistics['errors'][] = t('Для SKU ' . $sku . 'в регионе ' . $region_code . ' нет обязательного параметра city');
            $error_on_stick = TRUE;
          }
          if (empty($stock['address'])) {
            $statistics['errors'][] = t('Для SKU ' . $sku . 'в регионе ' . $region_code . ' нет обязательного параметра address');
            $error_on_stick = TRUE;
          }
          if (empty($stock['stock_id'])) {
            $statistics['errors'][] = t('Для SKU ' . $sku . 'в регионе ' . $region_code . ' нет обязательного параметра stock_id');
            $error_on_stick = TRUE;
          }
          if (empty($stock['price']) || $stock['price'] < 0) {
            $statistics['errors'][] = t('Для SKU ' . $sku . 'в регионе ' . $region_code . ' ошибка в параметре price');
            $error_on_stick = TRUE;
          }
          if (empty($stock['available']) || $stock['available'] < 0) {
            $statistics['errors'][] = t('Для SKU ' . $sku . 'в регионе ' . $region_code . ' ошибка в параметре available');
            $error_on_stick = TRUE;
          }
          if ($error_on_stick) {
            continue;
          }
          $data['stores'][$region_code]['city'] = $stock['city'];
          $stock_name = $stock['city'] . ', ' . $stock['address'];
          $stock_id = $partner_id . '_' . str_replace(' ', '_', $stock['stock_id']);
          $data['stocks'][$stock_id] = $stock_name;
          $data['stores'][$region_code]['stocks'][$stock_id] = $stock_id;
          if (!empty($size)) {
            $data['product_variations'][$region_code][$product->id()][$sku]['price'] = $stock['price'];
            $data['product_variations'][$region_code][$product->id()][$sku]['count'][$stock_id] = $stock['available'];
          }
        }
      }
    }

    return $data;
  }

}
