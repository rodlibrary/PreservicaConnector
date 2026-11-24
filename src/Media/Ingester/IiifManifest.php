<?php
namespace PreservicaConnector\Media\Ingester;

use Omeka\Media\Ingester\IiifPresentation;

/**
 * Omeka's IIIF presentation ingester, also accepting manifests whose
 * "@context" is a list.
 *
 * IIIF Presentation 3 manifests must give "@context" as a list when they use
 * extensions (the Presentation context last), but Omeka S core (4.1, 4.2)
 * only accepts a single string. Media made with this ingester render with
 * Omeka's own IIIF presentation renderer.
 */
class IiifManifest extends IiifPresentation
{
    public function getLabel()
    {
        return 'IIIF presentation (Preservica Connector)'; // @translate
    }

    public function getVersion(array $iiif)
    {
        if (isset($iiif['@context']) && is_array($iiif['@context'])) {
            foreach (array_reverse($iiif['@context']) as $context) {
                if (is_string($context) && preg_match('#^https?://iiif\.io/api/presentation/[23]/context\.json$#', $context)) {
                    $iiif['@context'] = $context;
                    break;
                }
            }
        }
        return parent::getVersion($iiif);
    }
}
