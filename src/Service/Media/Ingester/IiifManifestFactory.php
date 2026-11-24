<?php
namespace PreservicaConnector\Service\Media\Ingester;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use PreservicaConnector\Media\Ingester\IiifManifest;

class IiifManifestFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new IiifManifest(
            $services->get('Omeka\HttpClient'),
            $services->get('Omeka\File\Downloader')
        );
    }
}
