<?php
/**
 * Routes configuration.
 *
 * In this file, you set up routes to your controllers and their actions.
 * Routes are very important mechanism that allows you to freely connect
 * different URLs to chosen controllers and their actions (functions).
 *
 * It's loaded within the context of `Application::routes()` method which
 * receives a `RouteBuilder` instance `$routes` as method argument.
 *
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */

use Cake\Routing\Route\DashedRoute;
use Cake\Routing\RouteBuilder;

/*
 * This file is loaded in the context of the `Application` class.
 * So you can use `$this` to reference the application class instance
 * if required.
 */
return function (RouteBuilder $routes): void {
    /*
     * The default class to use for all routes
     *
     * The following route classes are supplied with CakePHP and are appropriate
     * to set as the default:
     *
     * - Route
     * - InflectedRoute
     * - DashedRoute
     *
     * If no call is made to `Router::defaultRouteClass()`, the class used is
     * `Route` (`Cake\Routing\Route\Route`)
     *
     * Note that `Route` does not do any inflections on URLs which will result in
     * inconsistently cased URLs when used with `{plugin}`, `{controller}` and
     * `{action}` markers.
     */
    $routes->setRouteClass(DashedRoute::class);

    /*
     * StayVanta JSON API. Token-based, stateless, no CSRF (see Application).
     * Controllers live in src/Controller/Api and extend Api\AppController.
     */
    $routes->prefix('Api', ['path' => '/api'], function (RouteBuilder $builder): void {
        $builder->setExtensions(['json']);

        $builder->post('/auth/login', ['controller' => 'Auth', 'action' => 'login']);
        $builder->post('/auth/logout', ['controller' => 'Auth', 'action' => 'logout']);
        $builder->get('/auth/me', ['controller' => 'Auth', 'action' => 'me']);

        // Properties (hotels & resorts).
        $builder->get('/properties', ['controller' => 'Properties', 'action' => 'index']);
        $builder->post('/properties', ['controller' => 'Properties', 'action' => 'add']);
        $builder->patch('/properties/{id}', ['controller' => 'Properties', 'action' => 'edit'])
            ->setPatterns(['id' => '\d+'])->setPass(['id']);
        $builder->put('/properties/{id}', ['controller' => 'Properties', 'action' => 'edit'])
            ->setPatterns(['id' => '\d+'])->setPass(['id']);

        // Role dashboards.
        $builder->get('/reports/owner-dashboard', ['controller' => 'Reports', 'action' => 'ownerDashboard']);
        $builder->get('/reports/admin-dashboard', ['controller' => 'Reports', 'action' => 'adminDashboard']);

        // Staff (users).
        $builder->get('/users', ['controller' => 'Users', 'action' => 'index']);
        $builder->post('/users', ['controller' => 'Users', 'action' => 'add']);
        $builder->patch('/users/{id}', ['controller' => 'Users', 'action' => 'edit'])
            ->setPatterns(['id' => '\d+'])->setPass(['id']);
        $builder->put('/users/{id}', ['controller' => 'Users', 'action' => 'edit'])
            ->setPatterns(['id' => '\d+'])->setPass(['id']);
        $builder->post('/users/{id}/reset-password', ['controller' => 'Users', 'action' => 'resetPassword'])
            ->setPatterns(['id' => '\d+'])->setPass(['id']);

        // Inventory module.
        $builder->get('/inventory-categories', ['controller' => 'InventoryCategories', 'action' => 'index']);
        $builder->post('/inventory-categories', ['controller' => 'InventoryCategories', 'action' => 'add']);

        $builder->get('/inventory-items', ['controller' => 'InventoryItems', 'action' => 'index']);
        $builder->post('/inventory-items', ['controller' => 'InventoryItems', 'action' => 'add']);
        $builder->get('/inventory-items/{id}', ['controller' => 'InventoryItems', 'action' => 'view'])
            ->setPatterns(['id' => '\d+'])->setPass(['id']);
        $builder->put('/inventory-items/{id}', ['controller' => 'InventoryItems', 'action' => 'edit'])
            ->setPatterns(['id' => '\d+'])->setPass(['id']);
        $builder->patch('/inventory-items/{id}', ['controller' => 'InventoryItems', 'action' => 'edit'])
            ->setPatterns(['id' => '\d+'])->setPass(['id']);
        $builder->delete('/inventory-items/{id}', ['controller' => 'InventoryItems', 'action' => 'delete'])
            ->setPatterns(['id' => '\d+'])->setPass(['id']);

        $builder->get('/stock-movements', ['controller' => 'StockMovements', 'action' => 'index']);
        $builder->post('/stock-movements', ['controller' => 'StockMovements', 'action' => 'add']);

        // Front Desk module: rooms, rates, reservations.
        $builder->get('/rooms', ['controller' => 'Rooms', 'action' => 'index']);
        $builder->post('/rooms', ['controller' => 'Rooms', 'action' => 'add']);
        $builder->patch('/rooms/{id}', ['controller' => 'Rooms', 'action' => 'edit'])
            ->setPatterns(['id' => '\d+'])->setPass(['id']);
        $builder->put('/rooms/{id}', ['controller' => 'Rooms', 'action' => 'edit'])
            ->setPatterns(['id' => '\d+'])->setPass(['id']);
        $builder->delete('/rooms/{id}', ['controller' => 'Rooms', 'action' => 'delete'])
            ->setPatterns(['id' => '\d+'])->setPass(['id']);

        $builder->get('/room-rates', ['controller' => 'RoomRates', 'action' => 'index']);
        $builder->post('/room-rates', ['controller' => 'RoomRates', 'action' => 'add']);
        $builder->patch('/room-rates/{id}', ['controller' => 'RoomRates', 'action' => 'edit'])
            ->setPatterns(['id' => '\d+'])->setPass(['id']);
        $builder->put('/room-rates/{id}', ['controller' => 'RoomRates', 'action' => 'edit'])
            ->setPatterns(['id' => '\d+'])->setPass(['id']);

        $builder->get('/reservations', ['controller' => 'Reservations', 'action' => 'index']);
        $builder->post('/reservations', ['controller' => 'Reservations', 'action' => 'add']);
        $builder->post('/reservations/{id}/{transition}', ['controller' => 'Reservations', 'action' => 'transition'])
            ->setPatterns(['id' => '\d+', 'transition' => 'check-in|check-out|cancel'])
            ->setPass(['id', 'transition']);

        // Guests module.
        $builder->get('/guests/stats', ['controller' => 'Guests', 'action' => 'stats']);
        $builder->get('/guests/match', ['controller' => 'Guests', 'action' => 'match']);
        $builder->get('/guests', ['controller' => 'Guests', 'action' => 'index']);
        $builder->post('/guests', ['controller' => 'Guests', 'action' => 'add']);
        $builder->get('/guests/{id}', ['controller' => 'Guests', 'action' => 'view'])
            ->setPatterns(['id' => '\d+'])->setPass(['id']);
        $builder->patch('/guests/{id}', ['controller' => 'Guests', 'action' => 'edit'])
            ->setPatterns(['id' => '\d+'])->setPass(['id']);
        $builder->put('/guests/{id}', ['controller' => 'Guests', 'action' => 'edit'])
            ->setPatterns(['id' => '\d+'])->setPass(['id']);

        // Food module: menu, orders, invoices.
        $builder->get('/food-menu-items', ['controller' => 'FoodMenuItems', 'action' => 'index']);
        $builder->post('/food-menu-items', ['controller' => 'FoodMenuItems', 'action' => 'add']);
        $builder->patch('/food-menu-items/{id}', ['controller' => 'FoodMenuItems', 'action' => 'edit'])
            ->setPatterns(['id' => '\d+'])->setPass(['id']);
        $builder->put('/food-menu-items/{id}', ['controller' => 'FoodMenuItems', 'action' => 'edit'])
            ->setPatterns(['id' => '\d+'])->setPass(['id']);
        $builder->delete('/food-menu-items/{id}', ['controller' => 'FoodMenuItems', 'action' => 'delete'])
            ->setPatterns(['id' => '\d+'])->setPass(['id']);

        $builder->get('/food-orders', ['controller' => 'FoodOrders', 'action' => 'index']);
        $builder->post('/food-orders', ['controller' => 'FoodOrders', 'action' => 'add']);
        $builder->get('/food-orders/{id}', ['controller' => 'FoodOrders', 'action' => 'view'])
            ->setPatterns(['id' => '\d+'])->setPass(['id']);
        $builder->post('/food-orders/{id}/serve', ['controller' => 'FoodOrders', 'action' => 'serve'])
            ->setPatterns(['id' => '\d+'])->setPass(['id']);
        $builder->post('/food-orders/{id}/cancel', ['controller' => 'FoodOrders', 'action' => 'cancel'])
            ->setPatterns(['id' => '\d+'])->setPass(['id']);

        $builder->get('/invoices', ['controller' => 'Invoices', 'action' => 'index']);
        $builder->get('/invoices/{id}', ['controller' => 'Invoices', 'action' => 'view'])
            ->setPatterns(['id' => '\d+'])->setPass(['id']);
        $builder->post('/invoices/{id}/settle', ['controller' => 'Invoices', 'action' => 'settle'])
            ->setPatterns(['id' => '\d+'])->setPass(['id']);

        $builder->fallbacks();
    });

    $routes->scope('/', function (RouteBuilder $builder): void {
        /*
         * Here, we are connecting '/' (base path) to a controller called 'Pages',
         * its action called 'display', and we pass a param to select the view file
         * to use (in this case, templates/Pages/home.php)...
         */
        $builder->connect('/', ['controller' => 'Pages', 'action' => 'display', 'home']);

        /*
         * ...and connect the rest of 'Pages' controller's URLs.
         */
        $builder->connect('/pages/*', 'Pages::display');

        /*
         * Connect catchall routes for all controllers.
         *
         * The `fallbacks` method is a shortcut for
         *
         * ```
         * $builder->connect('/{controller}', ['action' => 'index']);
         * $builder->connect('/{controller}/{action}/*', []);
         * ```
         *
         * It is NOT recommended to use fallback routes after your initial prototyping phase!
         * See https://book.cakephp.org/5/en/development/routing.html#fallbacks-method for more information
         */
        $builder->fallbacks();
    });

    /*
     * If you need a different set of middleware or none at all,
     * open new scope and define routes there.
     *
     * ```
     * $routes->scope('/api', function (RouteBuilder $builder): void {
     *     // No $builder->applyMiddleware() here.
     *
     *     // Parse specified extensions from URLs
     *     // $builder->setExtensions(['json', 'xml']);
     *
     *     // Connect API actions here.
     * });
     * ```
     */
};
