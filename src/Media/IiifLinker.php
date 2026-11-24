<?php
namespace PreservicaConnector\Media;

use Omeka\Settings\Settings;
use PreservicaConnector\Preservica\Client;
use PreservicaConnector\Preservica\Exception;

/**
 * Works out, for a Preservica asset, the IIIF manifest and discovery record
 * on the discovery platform, from the asset's shared identifier.
 *
 * The shared identifier is a Preservica external identifier of a configured
 * type; the URLs come from configured patterns with an {id} placeholder.
 */
class IiifLinker
{
    const MODE_COPY = 'copy';

    const MODE_IIIF = 'iiif';

    const PLACEHOLDER = '{id}';

    protected $client;

    protected $mode;

    protected $identifierType;

    protected $manifestPattern;

    protected $recordPattern;

    public function __construct(Client $client, $mode, $identifierType, $manifestPattern, $recordPattern = '')
    {
        $this->client = $client;
        $this->mode = $mode === self::MODE_IIIF ? self::MODE_IIIF : self::MODE_COPY;
        $this->identifierType = trim((string) $identifierType);
        $this->manifestPattern = trim((string) $manifestPattern);
        $this->recordPattern = trim((string) $recordPattern);
    }

    public static function fromSettings(Client $client, Settings $settings)
    {
        return new self(
            $client,
            $settings->get('preservicaconnector_media_mode', self::MODE_COPY),
            $settings->get('preservicaconnector_shared_id_type', ''),
            $settings->get('preservicaconnector_manifest_url', ''),
            $settings->get('preservicaconnector_record_url', '')
        );
    }

    /**
     * Is a URL pattern usable: http(s), with the {id} placeholder?
     */
    public static function isValidPattern($pattern)
    {
        $pattern = trim((string) $pattern);
        return strpos($pattern, self::PLACEHOLDER) !== false
            && preg_match('#^https?://[^/\s{}]+(/\S*)?$#i', str_replace(self::PLACEHOLDER, 'x', $pattern));
    }

    /**
     * Should imported items link to IIIF manifests instead of copying files?
     */
    public function isEnabled()
    {
        return $this->mode === self::MODE_IIIF;
    }

    public function getIdentifierType()
    {
        return $this->identifierType;
    }

    /**
     * Find an asset's shared identifier in Preservica.
     *
     * @return string|null
     */
    public function findSharedId($ref)
    {
        foreach ($this->client->getIdentifiers($ref) as $identifier) {
            // Identifier types are compared without regard to case or spacing.
            if ($this->normalize($identifier['type']) === $this->normalize($this->identifierType)
                && trim($identifier['value']) !== ''
            ) {
                return trim($identifier['value']);
            }
        }
        return null;
    }

    /**
     * The links for an asset.
     *
     * @param string $ref Preservica asset reference
     * @param string|null $sharedId Known shared identifier; looked up if null
     * @return array ['id', 'manifest', 'record' => string|null]
     * @throws Exception If the asset has no shared identifier
     */
    public function linksFor($ref, $sharedId = null)
    {
        if (!$this->isValidPattern($this->manifestPattern) || $this->identifierType === '') {
            throw new Exception('IIIF linking is not fully configured: set the shared identifier type and the IIIF manifest URL pattern.'); // @translate
        }
        $sharedId = $sharedId !== null && $sharedId !== '' ? $sharedId : $this->findSharedId($ref);
        if ($sharedId === null) {
            throw new Exception(sprintf(
                'It has no "%s" identifier in Preservica, so it cannot be linked to the discovery platform.',
                $this->identifierType
            ));
        }
        return [
            'id' => $sharedId,
            'manifest' => $this->fill($this->manifestPattern, $sharedId),
            'record' => $this->isValidPattern($this->recordPattern) ? $this->fill($this->recordPattern, $sharedId) : null,
        ];
    }

    protected function fill($pattern, $id)
    {
        return str_replace(self::PLACEHOLDER, rawurlencode($id), $pattern);
    }

    protected function normalize($type)
    {
        return strtolower(preg_replace('/\s+/', ' ', trim((string) $type)));
    }
}
