<?php

    // Include the Router class
    require_once __DIR__ . '/includes/autoloader.inc';

    // Create a Router
    $router = new \Bramus\Router\Router();

    // Custom 404 Handler
    $router->set404(function () {
        header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found');
        echo '404, route not found!';
    });

    // result route: /
    $router->get('/(results)', function ($module) {

        // call the tempate
        $main = new MainController($module);
        $main->run_analysis();
        $main->show_template();
    });

    // setup route: /
    $router->get('/(setup)', function ($module) {

        // call the tempate
        $main = new MainController($module);
        $main->run_analysis();
        $main->show_template();
    });

    // setup route: /
    $router->get('/(\w*)', function ($module) {

        //route to right location
        header('Location: /' . SITEURL . 'setup');
        exit();
    });

    // Thunderbirds are go!
    $router->run();

// EOF
