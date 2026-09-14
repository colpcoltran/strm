<?php
/**
 * Router pro vestavěný PHP server – napodobí přepisy z public/.htaccess,
 * které vestavěný server nečte. Použití (z kořene repozitáře):
 *   php -S localhost:8000 -t public scripts/dev-router.php
 */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
if ($path === '/admin/export.csv') {
    require __DIR__ . '/../public/admin/export.php';
    return true;
}
return false; // ostatní obslouží server sám (statické soubory, index.php v adresářích)
