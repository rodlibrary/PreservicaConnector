<?php
namespace PreservicaConnector\Service\Media\Ingester;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use PreservicaConnector\Media\Ingester\Preservica;
use PreservicaConnector\Preservica\Client;

class PreservicaFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new Preservica(
            $services->get(Client::class),
            $services->get('Omeka\File\TempFileFactory'),
            (bool) $services->get('Omeka\Settings')->get('preservicaconnector_fallback_preservation', true)
        );
    }
}
