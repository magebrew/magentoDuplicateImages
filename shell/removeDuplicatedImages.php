<?php
/**
 * Delete duplicated images
 *
 * @category   Magebrew
 * @author     Magebrew <magebrew.com>
 */

require_once 'abstract.php';

class Magebrew_Remove_Duplicated_Images extends Mage_Shell_Abstract
{
    /**
     * Run script
     *
     */
    public function run()
    {
        $installer = new Mage_Catalog_Model_Resource_Setup('core_setup');
        try {
            //get products that have more then one image assigned
            $mediaSubSelect = $installer->getConnection()->select()
                ->from(array('gallery' => $installer->getTable('catalog_product_entity_media_gallery')), array('id' => 'entity_id'))
                ->group('entity_id')
                ->having('count(entity_id) >1');

            //get id_to_all_assigned_images relation
            $mediaSelect = $installer->getConnection()->select()
                ->from(array('gallery' => $installer->getTable('catalog_product_entity_media_gallery')), array('id' => 'entity_id', 'images' => 'GROUP_CONCAT(value)'))
                ->join(
                    array('sub_media' => $mediaSubSelect),
                    'sub_media.id = gallery.entity_id',
                    array()
                )
                ->group('id');

            //get id_to_base_image relation
            $baseImageSelect = $installer->getConnection()->select()
                ->from(array('product' => $installer->getTable('catalog/product')), array('id' => 'product.entity_id'))
                ->join(
                    array('varchar_table' => $installer->getTable('catalog_product_entity_varchar')),
                    'varchar_table.entity_id = product.entity_id',
                    array('base_image' => 'value')
                )
                ->join(
                    array('eav_table' => $installer->getTable('eav/attribute')),
                    'varchar_table.attribute_id = eav_table.attribute_id',
                    array()
                )
                ->where('eav_table.attribute_code = "image" AND eav_table.entity_type_id = 4 AND varchar_table.value <> "no_selection" AND varchar_table.value <> "" AND varchar_table.store_id = 0');
            $idToBase = $installer->getConnection()->fetchPairs($baseImageSelect);
            $baseToId = array_flip($idToBase);
            $imagesToDelete = array();
            $csvFileHandle = fopen(Mage::getBaseDir('var') . DS . 'duplicated_images.csv', 'w');
            $query = $installer->getConnection()->query($mediaSelect);
            while ($row = $query->fetch()) {
                $md5 = array();
                foreach (explode(',', $row['images']) as $image) {
                    $filepath = Mage::getBaseDir('media') . '/catalog/product' . $image;
                    if (file_exists($filepath)) {
                        $md5Hash = md5_file($filepath);
                        if (!isset($md5[$md5Hash])) {
                            $md5[$md5Hash] = $image;
                        } else {
                            if (!isset($baseToId[$image])) {
                                $imagesToDelete[] = $image;
                                fputcsv($csvFileHandle, array($row['id'], $image));
                            } else {
                                $imagesToDelete[] = $md5[$md5Hash];
                                fputcsv($csvFileHandle, array($row['id'], $md5[$md5Hash]));
                            }
                        }
                    }
                }
            }
            fclose($csvFileHandle);
            $where = array(
                'value in(?)' => $imagesToDelete
            );
            echo 'executing of mass detele query ' . PHP_EOL;
            $installer->getConnection()->delete($installer->getTable('catalog_product_entity_media_gallery'), $where);
            foreach ($imagesToDelete as $image) {
                Mage::log($image, null, 'image_duplicate.log', true);

                if (unlink(Mage::getBaseDir('media') . '/catalog/product' . $image)) {
                    echo 'Deleted ' . Mage::getBaseDir('media') . '/catalog/product' . $image . PHP_EOL;
                } else {
                    echo 'Can not delete ' . Mage::getBaseDir('media') . '/catalog/product' . $image . PHP_EOL;
                }

            }echo 'Finished' . PHP_EOL;
        } catch (Exception $e) {
            echo $e->getMessage() . "\n";
        }
    }
}

$shell = new Magebrew_Remove_Duplicated_Images();
$shell->run();
