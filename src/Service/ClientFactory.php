<?php
namespace PreservicaConnector\Service;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Laminas\Session\Container;
use PreservicaConnector\Preservica\Client;

class ClientFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        $settings = $services->get('Omeka\Settings');
        // In the browser, keep the access token in the user's session so that
        // every page and thumbnail request does not log in again. Background
        // jobs run on the command line and keep it in memory.
        $tokenStore = PHP_SAPI === 'cli' ? null : new Container('PreservicaConnector');
        return new Client(
            function () use ($services) {
                // Omeka\HttpClient is a non-shared service: each call is a new client.
                return $services->get('Omeka\HttpClient');
            },
            $settings->get('preservicaconnector_host'),
            $settings->get('preservicaconnector_username'),
            $settings->get('preservicaconnector_password'),
            $settings->get('preservicaconnector_tenant'),
            $tokenStore
        );
    }
}
