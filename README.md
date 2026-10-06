# Optimización de PrestaShop mediante overrides

Este repositorio contiene tres overrides orientados a mejorar el rendimiento de tiendas PrestaShop con productos que tienen un número elevado de combinaciones.

Los overrides no modifican datos de la base de datos ni añaden tablas nuevas. Su objetivo es reducir consultas repetitivas, evitar cálculos innecesarios y mejorar los tiempos de respuesta en categorías, fichas de producto y carrito.

## Estructura

```text
override/
├── classes/
│   ├── db/
│   │   └── DbPDO.php
│   └── stock/
│       └── StockAvailable.php
└── controllers/
    └── front/
        └── ProductController.php
```

## Overrides incluidos

### `override/classes/db/DbPDO.php`

Optimiza determinadas consultas relacionadas con filtros de atributos cuando existen productos con muchas combinaciones.

MariaDB puede utilizar la optimización `split_materialized`, también conocida como `LATERAL DERIVED`. En algunos escenarios con búsquedas facetadas y grupos de atributos muy grandes, esta optimización puede provocar que una subconsulta se recalcule repetidamente y aumentar de forma drástica el tiempo de ejecución.

El override detecta consultas de filtros de atributos asociadas a productos con un número elevado de combinaciones y desactiva `split_materialized` únicamente para esas consultas.

Para el resto de consultas mantiene la configuración normal de MariaDB.

#### Umbral de combinaciones

El umbral se define mediante la constante:

```php
ECOM_OPT_COMBINACIONES
```

Por defecto puede establecerse, por ejemplo, en:

```php
200
```

Un grupo de atributos se considera pesado cuando está siendo utilizado por algún producto que supera dicho número de combinaciones.

Si se configura:

```php
0
```

la optimización `split_materialized` se desactiva en todos los filtros de atributos afectados por el override.

El valor óptimo depende del catálogo, número de combinaciones, versión de MariaDB y consultas generadas por la tienda.

---

### `override/classes/stock/StockAvailable.php`

Reduce el número de consultas necesarias para obtener el stock de productos con muchas combinaciones.

En determinados procesos, el núcleo puede solicitar la disponibilidad combinación por combinación. En productos con miles de variantes esto puede provocar miles de consultas SQL durante una única carga de página.

El override obtiene las cantidades necesarias de forma agrupada y reutiliza esos datos durante la ejecución.

Esto es especialmente útil en:

- fichas con muchas combinaciones;
- productos presentes en el carrito;
- páginas donde PrestaShop necesita comprobar repetidamente el stock de variantes.

El resultado funcional debe ser el mismo que el del núcleo; cambia únicamente la forma de obtener los datos.

---

### `override/controllers/front/ProductController.php`

Optimiza parte del procesamiento de la ficha de producto cuando existen muchas combinaciones.

En catálogos muy grandes, la generación de la información de combinaciones puede provocar:

- cálculos de precio repetidos;
- accesos a disco repetitivos;
- procesamiento redundante por cada combinación;
- mayor coste al cambiar de variante.

El override reutiliza cálculos y datos cuando es posible para evitar repetir operaciones equivalentes miles de veces.

## Instalación

1. Haz una copia de seguridad de la carpeta:

```text
override/
```

2. Copia el contenido de este repositorio sobre la carpeta `override/` de PrestaShop respetando las rutas:

```text
override/classes/db/DbPDO.php
override/classes/stock/StockAvailable.php
override/controllers/front/ProductController.php
```

3. Elimina el índice de clases generado por PrestaShop:

```text
var/cache/prod/class_index.php
var/cache/dev/class_index.php
```

PrestaShop volverá a generarlo automáticamente.

4. Vacía también la caché de PrestaShop si fuera necesario.

5. Comprueba al menos:

- carga de categorías con filtros;
- apertura de productos con muchas combinaciones;
- cambio de atributos;
- stock mostrado;
- precios;
- descuentos;
- añadir al carrito;
- cambio de cantidades;
- proceso de compra completo.

## Desinstalación

Elimina los tres ficheros:

```text
override/classes/db/DbPDO.php
override/classes/stock/StockAvailable.php
override/controllers/front/ProductController.php
```

Después vuelve a eliminar:

```text
var/cache/prod/class_index.php
var/cache/dev/class_index.php
```

Los overrides no requieren cambios adicionales en la base de datos.

## Compatibilidad

### `DbPDO.php`

El override depende del comportamiento de la capa de base de datos de PrestaShop y de MariaDB.

Conviene revisarlo después de:

- actualizar PrestaShop;
- cambiar de MariaDB a MySQL;
- actualizar de forma importante MariaDB;
- modificar el sistema de filtros de atributos.

### `StockAvailable.php`

Debe revisarse si PrestaShop cambia la firma o el comportamiento interno de los métodos sobrescritos.

Después de una actualización mayor de PrestaShop es recomendable comparar el override con la versión correspondiente de:

```text
classes/stock/StockAvailable.php
```

### `ProductController.php`

Es el override más sensible a cambios entre versiones porque optimiza lógica interna del controlador de producto.

Debe revisarse especialmente al cambiar de una rama mayor de PrestaShop a otra.

Antes de utilizarlo en una versión distinta de aquella para la que fue creado, compara los métodos sobrescritos con el núcleo actual.

## Recomendaciones después de actualizar PrestaShop

Después de cualquier actualización:

1. compara cada override con su clase original;
2. revisa las firmas de los métodos;
3. comprueba si el núcleo ha incorporado cambios funcionales;
4. vuelve a ejecutar pruebas de precio, stock y combinaciones;
5. revisa los logs de PHP y PrestaShop.

No conviene asumir que un override seguirá siendo válido indefinidamente solo porque PHP no genere errores.

## Pruebas recomendadas

Para verificar que el comportamiento sigue siendo correcto, prueba productos con:

- pocas combinaciones;
- cientos de combinaciones;
- miles de combinaciones;
- combinaciones sin stock;
- combinaciones con stock;
- precios específicos;
- descuentos;
- impuestos;
- diferentes grupos de clientes;
- diferentes monedas, si se utilizan;
- diferentes tiendas, si se utiliza multitienda.

También es recomendable comparar el resultado con y sin overrides.

## Medición de rendimiento

Para comprobar la mejora real puede medirse:

- tiempo de respuesta del servidor;
- tiempo total de consultas SQL;
- número total de consultas;
- consultas repetidas;
- tiempo de generación de la ficha;
- tiempo de cambio de combinación;
- consumo de memoria.

En consultas SQL problemáticas puede resultar útil utilizar:

```sql
EXPLAIN
```

o:

```sql
ANALYZE
```

según la versión del servidor de base de datos.

## Importante

Estos overrides están pensados para optimizar cuellos de botella concretos en catálogos con muchas combinaciones.

Antes de utilizarlos en producción:

- realiza una copia de seguridad;
- pruébalos en staging;
- revisa la compatibilidad con tu versión exacta de PrestaShop;
- comprueba precios, stock y checkout;
- elimina la caché de clases después de cualquier cambio en `override/`.

## Licencia

Puedes añadir aquí la licencia que quieras aplicar al repositorio.
