<?php
namespace PreservicaConnector\Service\Controller;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use PreservicaConnector\Controller\IndexController;
use PreservicaConnector\Preservica\Client;

class IndexControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new IndexController(
            $services->get(Client::class),
            $services->get('Omeka\Connection')
        );
    }
}
