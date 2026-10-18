<?php declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

// Admin-API scope without login: the secret token in the address is the protection.
return static function (RoutingConfigurator $routes): void {
    $routes->add('api.cronjob_de.run', '/api/cronjob-de/run')
        ->controller('CronjobDe\TaskRunner\Controller\RunController::run')
        ->methods(['GET', 'POST'])
        ->defaults([
            '_routeScope' => ['api'],
            'auth_required' => false,
        ]);
};
