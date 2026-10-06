<?php
/**
 * Optimización de rendimiento para catálogos con miles de combinaciones.
 *
 * El núcleo pregunta el stock de las combinaciones DE UNA EN UNA: quien recorre todas las
 * de un producto (Product::getAttributeCombinations(), getAttributesResume(), y los módulos
 * que los llaman) lanza una consulta por combinación. En un producto de 8.032 combinaciones
 * son 8.032 consultas en cada visita a su ficha.
 *
 * Aquí, a partir de la cuarta combinación distinta que se pide de un mismo producto, se
 * traen las cantidades de TODAS sus combinaciones con una sola consulta y el resto se
 * contesta de memoria. El resultado es el mismo número que devuelve el núcleo.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License (AFL 3.0)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class StockAvailable extends StockAvailableCore
{
    /** Combinaciones sueltas que se dejan pasar al núcleo antes de pedir el producto entero */
    const ECOM_OPT_UMBRAL = 3;

    /** Productos cuyas cantidades se guardan a la vez en memoria */
    const ECOM_OPT_MAX_PRODUCTOS = 4;

    /** @var array [centinela => combinaciones distintas pedidas al núcleo] */
    protected static $ecomOptLlamadas = array();

    /** @var array [centinela => [id_product_attribute => cantidad]] */
    protected static $ecomOptLote = array();

    /**
     * Cantidad disponible de un producto o de una de sus combinaciones.
     *
     * @param int|null $id_product
     * @param int|null $id_product_attribute
     * @param int|null $id_shop
     *
     * @return int
     */
    public static function getQuantityAvailableByProduct($id_product = null, $id_product_attribute = null, $id_shop = null)
    {
        $idProducto = (int) $id_product;
        $idCombinacion = (int) $id_product_attribute;

        // Solo se trata el caso que se repite miles de veces: una combinación concreta de un
        // producto concreto. Todo lo demás lo sigue contestando el núcleo.
        if ($id_product === null || $idProducto <= 0 || $idCombinacion <= 0
            || ($id_shop !== null && !is_numeric($id_shop))
        ) {
            return parent::getQuantityAvailableByProduct($id_product, $id_product_attribute, $id_shop);
        }

        $prefijo = 'StockAvailable::getQuantityAvailableByProduct_' . $idProducto . '-';

        // Si el núcleo ya la tiene en su caché, manda la suya.
        $claveNucleo = $prefijo . $idCombinacion . '-' . (int) $id_shop;
        if (Cache::isStored($claveNucleo)) {
            return Cache::retrieve($claveNucleo);
        }

        // El centinela vive en la caché del núcleo con el mismo prefijo que sus claves: cada
        // vez que el núcleo invalida el stock de este producto (Cache::clean(prefijo*) al
        // vender, al guardar cantidades, al sincronizar) el centinela desaparece con ellas,
        // y lo guardado aquí deja de usarse.
        $centinela = $prefijo . 'lote-' . (int) $id_shop;
        if (isset(self::$ecomOptLote[$centinela]) && Cache::isStored($centinela)) {
            return isset(self::$ecomOptLote[$centinela][$idCombinacion])
                ? self::$ecomOptLote[$centinela][$idCombinacion]
                : 0;
        }

        if (!isset(self::$ecomOptLlamadas[$centinela])) {
            self::$ecomOptLlamadas[$centinela] = 0;
        }
        if (++self::$ecomOptLlamadas[$centinela] <= self::ECOM_OPT_UMBRAL) {
            return parent::getQuantityAvailableByProduct($id_product, $id_product_attribute, $id_shop);
        }

        // Misma consulta que el núcleo, pero para todas las combinaciones del producto.
        $query = new DbQuery();
        $query->select('id_product_attribute, SUM(quantity) AS quantity');
        $query->from('stock_available');
        $query->where('id_product = ' . $idProducto);
        $query = StockAvailable::addSqlShopRestriction($query, $id_shop);
        $query->groupBy('id_product_attribute');

        $filas = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($query);
        if (!is_array($filas)) {
            return parent::getQuantityAvailableByProduct($id_product, $id_product_attribute, $id_shop);
        }

        $lote = array();
        foreach ($filas as $fila) {
            $lote[(int) $fila['id_product_attribute']] = (int) $fila['quantity'];
        }

        if (count(self::$ecomOptLote) >= self::ECOM_OPT_MAX_PRODUCTOS) {
            self::$ecomOptLote = array();
        }
        self::$ecomOptLote[$centinela] = $lote;
        Cache::store($centinela, true);

        return isset($lote[$idCombinacion]) ? $lote[$idCombinacion] : 0;
    }
}
