<?php
/**
 * Optimización de rendimiento para catálogos con miles de combinaciones.
 *
 * MariaDB 10.3+ trae encendida la optimización «split_materialized» (LATERAL DERIVED).
 * En las consultas del buscador por facetas (ps_facetedsearch) casi siempre ayuda, pero
 * cuando el filtro es de un grupo de atributos que usa un producto con cientos o miles
 * de combinaciones, hace que la subconsulta de productos se recalcule una vez por cada
 * combinación: el coste crece con el CUADRADO de las combinaciones. El filtro «Tamaño»
 * de la categoría «Benjamin Moore» (productos de 4.016 y 8.032 combinaciones) pasa de
 * 0,15 s a más de 5 minutos.
 *
 * Aquí se apaga SOLO para esas consultas: las de un filtro cuyo grupo de atributos lo usa
 * algún producto con más de ECOM_OPT_COMBINACIONES combinaciones. En todas las demás
 * MariaDB sigue con su optimización de serie, que es donde gana.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License (AFL 3.0)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class DbPDO extends DbPDOCore
{
    /**
     * A partir de cuántas combinaciones en UN producto se considera pesado el grupo de
     * atributos que usa. 0 = apagar la optimización en todos los filtros de atributos.
     */
    const ECOM_OPT_COMBINACIONES = 200;

    /**
     * @var string|bool|null null = sin mirar todavía; 'serie' = como viene el servidor;
     *                       'apagada' = apagada en esta conexión; false = no se toca
     */
    protected $ecomOptEstado = null;

    /** @var array|bool|null [id_attribute_group => true] de los grupos pesados; false si no se pudo saber */
    protected $ecomOptGruposPesados = null;

    /**
     * Ejecuta la consulta como el núcleo. Antes, si es una consulta con tabla derivada,
     * deja «split_materialized» como le conviene a esa consulta.
     *
     * @param string $sql
     *
     * @return PDOStatement|bool
     */
    protected function _query($sql)
    {
        if ($this->ecomOptEstado !== false && is_string($sql) && strpos($sql, 'FROM (SELECT ') !== false) {
            $this->ecomOptAjustar($sql);
        }

        return parent::_query($sql);
    }

    /**
     * Apaga la optimización si la consulta es de un filtro de un grupo de atributos pesado,
     * y la devuelve a como viene de serie si no lo es.
     *
     * @param string $sql
     */
    protected function ecomOptAjustar($sql)
    {
        if ($this->ecomOptEstado === null) {
            if (!$this->ecomOptServidorConSplit()) {
                $this->ecomOptEstado = false;

                return;
            }
            $this->ecomOptEstado = 'serie';
        }

        // ps_facetedsearch cuenta cada filtro de atributos con «a.id_attribute_group=N».
        $apagar = false;
        if (preg_match_all('/id_attribute_group\s*=\s*(\d+)/', $sql, $m)) {
            if ($this->ecomOptGruposPesados === null) {
                $this->ecomOptGruposPesados = $this->ecomOptBuscarGruposPesados();
            }
            if ($this->ecomOptGruposPesados === false) {
                // No se ha podido saber: se apaga, que es lo que no deja la tienda colgada.
                $apagar = true;
            } else {
                foreach ($m[1] as $idGrupo) {
                    if (isset($this->ecomOptGruposPesados[(int) $idGrupo])) {
                        $apagar = true;

                        break;
                    }
                }
            }
        }

        $deseado = $apagar ? 'apagada' : 'serie';
        if ($deseado === $this->ecomOptEstado) {
            return;
        }

        try {
            $hecho = $this->link->exec(
                'SET SESSION optimizer_switch = \'split_materialized=' . ($apagar ? 'off' : 'default') . '\''
            );
            $this->ecomOptEstado = ($hecho === false) ? false : $deseado;
        } catch (Exception $e) {
            // Si el servidor no lo admite no se vuelve a intentar.
            $this->ecomOptEstado = false;
        }
    }

    /**
     * ¿Es un MariaDB que trae «split_materialized» (10.3.4 o posterior)?
     *
     * @return bool
     */
    protected function ecomOptServidorConSplit()
    {
        try {
            $version = (string) $this->link->getAttribute(PDO::ATTR_SERVER_VERSION);
        } catch (Exception $e) {
            return false;
        }
        // «10.6.28-MariaDB-log» o «5.5.5-10.6.28-MariaDB»
        if (!preg_match('/(\d+)\.(\d+)\.(\d+)-MariaDB/i', $version, $m)) {
            return false;
        }

        return version_compare($m[1] . '.' . $m[2] . '.' . $m[3], '10.3.4', '>=');
    }

    /**
     * Grupos de atributos que usa algún producto con más combinaciones que el umbral.
     * Se miran la primera y la última combinación de cada producto pesado: todas las
     * combinaciones de un producto usan los mismos grupos.
     *
     * @return array|bool [id_attribute_group => true], o false si no se ha podido saber
     */
    protected function ecomOptBuscarGruposPesados()
    {
        try {
            $consulta = $this->link->query(
                'SELECT MIN(`id_product_attribute`), MAX(`id_product_attribute`)
                FROM `' . _DB_PREFIX_ . 'product_attribute`
                GROUP BY `id_product` HAVING COUNT(*) > ' . (int) self::ECOM_OPT_COMBINACIONES
            );
            if (!$consulta) {
                return false;
            }
            $combinaciones = array();
            foreach ($consulta->fetchAll(PDO::FETCH_NUM) as $fila) {
                $combinaciones[(int) $fila[0]] = true;
                $combinaciones[(int) $fila[1]] = true;
            }
            $consulta->closeCursor();
            if (!$combinaciones) {
                return array();
            }

            $consulta = $this->link->query(
                'SELECT DISTINCT a.`id_attribute_group`
                FROM `' . _DB_PREFIX_ . 'product_attribute_combination` pac
                INNER JOIN `' . _DB_PREFIX_ . 'attribute` a ON a.`id_attribute` = pac.`id_attribute`
                WHERE pac.`id_product_attribute` IN (' . implode(',', array_keys($combinaciones)) . ')'
            );
            if (!$consulta) {
                return false;
            }
            $grupos = array();
            foreach ($consulta->fetchAll(PDO::FETCH_NUM) as $fila) {
                $grupos[(int) $fila[0]] = true;
            }
            $consulta->closeCursor();

            return $grupos;
        } catch (Exception $e) {
            return false;
        }
    }
}
