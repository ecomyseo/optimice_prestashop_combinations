<?php
/**
 * Optimización de rendimiento para fichas de producto con miles de combinaciones.
 *
 * El núcleo, cada vez que pinta una ficha (y otra vez en cada cambio de variante, que
 * vuelve a pedirla por AJAX), recorre TODAS las combinaciones del producto y por cada una:
 *   - mira dos veces en disco si el atributo tiene imagen de textura;
 *   - calcula un precio completo solo para saber si lleva precio específico;
 *   - pasa por Tools::str2url() nueve campos de cada combinación.
 *
 * Aquí se hace exactamente lo mismo, con el mismo resultado asignado a la plantilla, pero
 * una vez por atributo distinto en lugar de una vez por combinación.
 *
 * Es una copia optimizada de dos métodos del núcleo 8.2.x. En cualquier otra versión de
 * PrestaShop este fichero no hace nada y manda el núcleo.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License (AFL 3.0)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class ProductController extends ProductControllerCore
{
    /**
     * Los dos métodos están contrastados línea a línea con el núcleo 8.2.x.
     *
     * @return bool
     */
    protected function ecomOptVersionVerificada()
    {
        return version_compare(_PS_VERSION_, '8.2.0', '>=') && version_compare(_PS_VERSION_, '8.3.0', '<');
    }

    /**
     * Combinaciones de este producto que tienen un precio específico PROPIO.
     * Las demás comparten todas el mismo (el del producto, o ninguno).
     *
     * @return array|null [id_product_attribute => true], o null si no se puede atajar
     */
    protected function ecomOptCombinacionesConPrecioPropio()
    {
        // Si alguien cambia el cálculo de precios (un override o un módulo enganchado), no
        // se ataja nada: se deja el camino del núcleo, combinación a combinación.
        try {
            $metodos = array(
                array('Product', 'getPriceStatic', 'ProductCore'),
                array('Product', 'priceCalculation', 'ProductCore'),
                array('SpecificPrice', 'getSpecificPrice', 'SpecificPriceCore'),
            );
            foreach ($metodos as $m) {
                $reflexion = new ReflectionMethod($m[0], $m[1]);
                if ($reflexion->getDeclaringClass()->getName() !== $m[2]) {
                    return null;
                }
            }
        } catch (Exception $e) {
            return null;
        }
        if (Hook::getHookModuleExecList('actionProductPriceCalculation')) {
            return null;
        }

        $filas = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS(
            'SELECT DISTINCT `id_product_attribute`
            FROM `' . _DB_PREFIX_ . 'specific_price`
            WHERE `id_product_attribute` != 0 AND `id_product` IN (0, ' . (int) $this->product->id . ')'
        );
        if (!is_array($filas)) {
            return null;
        }

        $propias = array();
        foreach ($filas as $fila) {
            $propias[(int) $fila['id_product_attribute']] = true;
        }

        return $propias;
    }

    /**
     * Assign template vars related to attribute groups and colors.
     */
    protected function assignAttributesGroups($product_for_template = null)
    {
        if (!$this->ecomOptVersionVerificada()) {
            parent::assignAttributesGroups($product_for_template);

            return;
        }

        $colors = [];
        $groups = [];
        $this->combinations = [];

        /** @todo (RM) should only get groups and not all declination ? */
        $attributes_groups = $this->product->getAttributesGroups($this->context->language->id);
        if (is_array($attributes_groups) && $attributes_groups) {
            $combination_images = $this->product->getCombinationImages($this->context->language->id);
            $combination_prices_set = [];

            // [ECOM-OPT] textura de cada atributo: se mira en disco una vez por atributo
            $texturas = [];
            // [ECOM-OPT] precio específico común a las combinaciones que no tienen uno propio
            $con_precio_propio = $this->ecomOptCombinacionesConPrecioPropio();
            $atajo_precio = is_array($con_precio_propio);
            $precio_comun_listo = false;
            $precio_comun = null;

            foreach ($attributes_groups as $k => $row) {
                $id_attribute = $row['id_attribute'];
                if (!isset($texturas[$id_attribute])) {
                    $fichero_textura = _PS_COL_IMG_DIR_ . $id_attribute . '.jpg';
                    $hay_fichero = file_exists($fichero_textura);
                    $texturas[$id_attribute] = [
                        $hay_fichero,
                        ($hay_fichero && @filemtime($fichero_textura)) ? _THEME_COL_DIR_ . $id_attribute . '.jpg' : '',
                    ];
                }

                // Color management
                if (isset($row['is_color_group']) && $row['is_color_group'] && (isset($row['attribute_color']) && $row['attribute_color']) || $texturas[$id_attribute][0]) {
                    $colors[$row['id_attribute']]['value'] = $row['attribute_color'];
                    $colors[$row['id_attribute']]['name'] = $row['attribute_name'];
                    if (!isset($colors[$row['id_attribute']]['attributes_quantity'])) {
                        $colors[$row['id_attribute']]['attributes_quantity'] = 0;
                    }
                    $colors[$row['id_attribute']]['attributes_quantity'] += (int) $row['quantity'];
                }
                if (!isset($groups[$row['id_attribute_group']])) {
                    $groups[$row['id_attribute_group']] = [
                        'group_name' => $row['group_name'],
                        'name' => $row['public_group_name'],
                        'group_type' => $row['group_type'],
                        'default' => -1,
                    ];
                }

                $groups[$row['id_attribute_group']]['attributes'][$row['id_attribute']] = [
                    'name' => $row['attribute_name'],
                    'html_color_code' => $row['attribute_color'],
                    'texture' => $texturas[$id_attribute][1],
                    'selected' => (isset($product_for_template['attributes'][$row['id_attribute_group']]['id_attribute']) && $product_for_template['attributes'][$row['id_attribute_group']]['id_attribute'] == $row['id_attribute']) ? true : false,
                ];

                //$product.attributes.$id_attribute_group.id_attribute eq $id_attribute
                if ($row['default_on'] && $groups[$row['id_attribute_group']]['default'] == -1) {
                    $groups[$row['id_attribute_group']]['default'] = (int) $row['id_attribute'];
                }
                if (!isset($groups[$row['id_attribute_group']]['attributes_quantity'][$row['id_attribute']])) {
                    $groups[$row['id_attribute_group']]['attributes_quantity'][$row['id_attribute']] = 0;
                }
                $groups[$row['id_attribute_group']]['attributes_quantity'][$row['id_attribute']] += (int) $row['quantity'];

                $this->combinations[$row['id_product_attribute']]['attributes_values'][$row['id_attribute_group']] = $row['attribute_name'];
                $this->combinations[$row['id_product_attribute']]['attributes'][] = (int) $row['id_attribute'];
                $this->combinations[$row['id_product_attribute']]['price'] = (float) $row['price'];

                // Call getPriceStatic in order to set $combination_specific_price
                if (!isset($combination_prices_set[(int) $row['id_product_attribute']])) {
                    $id_combinacion = (int) $row['id_product_attribute'];
                    if ($atajo_precio && $precio_comun_listo && !isset($con_precio_propio[$id_combinacion])) {
                        // [ECOM-OPT] mismo precio específico que el resto: no se recalcula
                        $combination_specific_price = $precio_comun;
                    } else {
                        $combination_specific_price = null;
                        Product::getPriceStatic((int) $this->product->id, false, $row['id_product_attribute'], 6, null, false, true, 1, false, null, null, null, $combination_specific_price);
                        if ($atajo_precio && !isset($con_precio_propio[$id_combinacion])) {
                            if (!$combination_specific_price || $combination_specific_price['price'] < 0) {
                                $precio_comun = $combination_specific_price;
                                $precio_comun_listo = true;
                            } else {
                                // Precio fijo: el núcleo lo convierte de moneda en cada
                                // combinación. Se deja todo al núcleo.
                                $atajo_precio = false;
                            }
                        }
                    }
                    $combination_prices_set[(int) $row['id_product_attribute']] = true;
                    $this->combinations[$row['id_product_attribute']]['specific_price'] = $combination_specific_price;
                }
                $this->combinations[$row['id_product_attribute']]['ecotax'] = (float) $row['ecotax'];
                $this->combinations[$row['id_product_attribute']]['weight'] = (float) $row['weight'];
                $this->combinations[$row['id_product_attribute']]['quantity'] = (int) $row['quantity'];
                $this->combinations[$row['id_product_attribute']]['reference'] = $row['reference'];
                $this->combinations[$row['id_product_attribute']]['ean13'] = $row['ean13'];
                $this->combinations[$row['id_product_attribute']]['mpn'] = $row['mpn'];
                $this->combinations[$row['id_product_attribute']]['upc'] = $row['upc'];
                $this->combinations[$row['id_product_attribute']]['isbn'] = $row['isbn'];
                $this->combinations[$row['id_product_attribute']]['unit_impact'] = $row['unit_price_impact'];
                $this->combinations[$row['id_product_attribute']]['minimal_quantity'] = $row['minimal_quantity'];
                if (!empty($row['available_date']) && $row['available_date'] != '0000-00-00' && Validate::isDate($row['available_date'])) {
                    $this->combinations[$row['id_product_attribute']]['available_date'] = $row['available_date'];
                    $this->combinations[$row['id_product_attribute']]['date_formatted'] = Tools::displayDate($row['available_date']);
                } else {
                    $this->combinations[$row['id_product_attribute']]['available_date'] = $this->combinations[$row['id_product_attribute']]['date_formatted'] = '';
                }
                $this->combinations[$row['id_product_attribute']]['available_now'] = $row['available_now'];
                $this->combinations[$row['id_product_attribute']]['available_later'] = $row['available_later'];

                if (!isset($combination_images[$row['id_product_attribute']][0]['id_image'])) {
                    $this->combinations[$row['id_product_attribute']]['id_image'] = -1;
                } else {
                    $this->combinations[$row['id_product_attribute']]['id_image'] = $id_image = (int) $combination_images[$row['id_product_attribute']][0]['id_image'];
                    if ($row['default_on']) {
                        foreach ($this->context->smarty->tpl_vars['product']->value['images'] as $image) {
                            if ($image['cover'] == 1) {
                                $current_cover = $image;
                            }
                        }
                        if (!isset($current_cover)) {
                            $current_cover = array_values($this->context->smarty->tpl_vars['product']->value['images'])[0];
                        }

                        if (is_array($combination_images[$row['id_product_attribute']])) {
                            foreach ($combination_images[$row['id_product_attribute']] as $tmp) {
                                if ($tmp['id_image'] == $current_cover['id_image']) {
                                    $this->combinations[$row['id_product_attribute']]['id_image'] = $id_image = (int) $tmp['id_image'];

                                    break;
                                }
                            }
                        }

                        if ($id_image > 0) {
                            if (isset($this->context->smarty->tpl_vars['images']->value)) {
                                $product_images = $this->context->smarty->tpl_vars['images']->value;
                            }
                            if (isset($product_images) && is_array($product_images) && isset($product_images[$id_image])) {
                                $product_images[$id_image]['cover'] = 1;
                                $this->context->smarty->assign('mainImage', $product_images[$id_image]);
                                $this->context->smarty->assign('images', $product_images);
                            }

                            $cover = $current_cover;

                            if (isset($cover) && is_array($cover) && isset($product_images) && is_array($product_images)) {
                                $product_images[$cover['id_image']]['cover'] = 0;
                                if (isset($product_images[$id_image])) {
                                    $cover = $product_images[$id_image];
                                }
                                $cover['id_image'] = (Configuration::get('PS_LEGACY_IMAGES') ? ($this->product->id . '-' . $id_image) : (int) $id_image);
                                $cover['id_image_only'] = (int) $id_image;
                                $this->context->smarty->assign('cover', $cover);
                            }
                        }
                    }
                }
            }

            // wash attributes list depending on available attributes depending on selected preceding attributes
            $current_selected_attributes = [];
            $count = 0;
            foreach ($groups as &$group) {
                ++$count;
                if ($count > 1) {
                    //find attributes of current group, having a possible combination with current selected
                    $id_product_attributes = [0];
                    $query = 'SELECT pac.`id_product_attribute`
                        FROM `' . _DB_PREFIX_ . 'product_attribute_combination` pac
                        INNER JOIN `' . _DB_PREFIX_ . 'product_attribute` pa ON pa.id_product_attribute = pac.id_product_attribute
                        WHERE id_product = ' . $this->product->id . ' AND id_attribute IN (' . implode(',', array_map('intval', $current_selected_attributes)) . ')
                        GROUP BY id_product_attribute
                        HAVING COUNT(id_product) = ' . count($current_selected_attributes);
                    if ($results = Db::getInstance()->executeS($query)) {
                        foreach ($results as $row) {
                            $id_product_attributes[] = $row['id_product_attribute'];
                        }
                    }
                    $id_attributes = Db::getInstance()->executeS('SELECT pac2.`id_attribute` FROM `' . _DB_PREFIX_ . 'product_attribute_combination` pac2' .
                        ((!Product::isAvailableWhenOutOfStock($this->product->out_of_stock) && 0 == Configuration::get('PS_DISP_UNAVAILABLE_ATTR')) ?
                        ' INNER JOIN `' . _DB_PREFIX_ . 'stock_available` pa ON pa.id_product_attribute = pac2.id_product_attribute
                        WHERE pa.quantity > 0 AND ' :
                        ' WHERE ') .
                        'pac2.`id_product_attribute` IN (' . implode(',', array_map('intval', $id_product_attributes)) . ')
                        AND pac2.id_attribute NOT IN (' . implode(',', array_map('intval', $current_selected_attributes)) . ')');
                    // [ECOM-OPT] búsqueda por clave en lugar de in_array() sobre miles de valores
                    $id_attributes_posibles = [];
                    foreach (is_array($id_attributes) ? $id_attributes : [] as $row) {
                        $id_attributes_posibles[(int) $row['id_attribute']] = true;
                    }
                    foreach ($group['attributes'] as $key => $attribute) {
                        if (!isset($id_attributes_posibles[(int) $key])) {
                            unset(
                                $group['attributes'][$key],
                                $group['attributes_quantity'][$key]
                            );
                        }
                    }
                }
                //find selected attribute or first of group
                $index = 0;
                $current_selected_attribute = 0;
                foreach ($group['attributes'] as $key => $attribute) {
                    if ($index === 0) {
                        $current_selected_attribute = $key;
                    }
                    if ($attribute['selected']) {
                        $current_selected_attribute = $key;

                        break;
                    }
                }
                if ($current_selected_attribute > 0) {
                    $current_selected_attributes[] = $current_selected_attribute;
                }
            }

            // wash attributes list (if some attributes are unavailables and if allowed to wash it)
            if (!Product::isAvailableWhenOutOfStock($this->product->out_of_stock) && Configuration::get('PS_DISP_UNAVAILABLE_ATTR') == 0) {
                foreach ($groups as &$group) {
                    foreach ($group['attributes_quantity'] as $key => $quantity) {
                        if ($quantity <= 0) {
                            unset($group['attributes'][$key]);
                        }
                    }
                }

                foreach ($colors as $key => $color) {
                    if ($color['attributes_quantity'] <= 0) {
                        unset($colors[$key]);
                    }
                }
            }
            foreach ($this->combinations as $id_product_attribute => $comb) {
                $attribute_list = '';
                foreach ($comb['attributes'] as $id_attribute) {
                    $attribute_list .= '\'' . (int) $id_attribute . '\',';
                }
                $attribute_list = rtrim($attribute_list, ',');
                $this->combinations[$id_product_attribute]['list'] = $attribute_list;
            }
            unset($group);

            $this->context->smarty->assign([
                'groups' => $groups,
                'colors' => (count($colors)) ? $colors : false,
                'combinations' => $this->combinations,
                'combinationImages' => $combination_images,
            ]);
        } else {
            $this->context->smarty->assign([
                'groups' => [],
                'colors' => false,
                'combinations' => [],
                'combinationImages' => [],
            ]);
        }
    }

    /**
     * Get and assign attributes combinations informations.
     */
    protected function assignAttributesCombinations(): void
    {
        if (!$this->ecomOptVersionVerificada()) {
            parent::assignAttributesCombinations();

            return;
        }

        $attributes_combinations = Product::getAttributesInformationsByProduct($this->product->id);
        if (is_array($attributes_combinations) && count($attributes_combinations)) {
            // [ECOM-OPT] cada valor distinto se transforma una sola vez
            $separador = Configuration::get('PS_ATTRIBUTE_ANCHOR_SEPARATOR');
            $transformados = [];
            foreach ($attributes_combinations as &$ac) {
                foreach ($ac as &$val) {
                    $clave = (string) $val;
                    if (!isset($transformados[$clave])) {
                        $transformados[$clave] = str_replace($separador, '_', Tools::str2url(str_replace([',', '.'], '-', $val)));
                    }
                    $val = $transformados[$clave];
                }
            }
            unset($ac, $val);
        } else {
            $attributes_combinations = [];
        }
        $this->context->smarty->assign([
            'attributesCombinations' => $attributes_combinations,
            'attribute_anchor_separator' => Configuration::get('PS_ATTRIBUTE_ANCHOR_SEPARATOR'),
        ]);
    }
}
