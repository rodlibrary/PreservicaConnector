<?php
namespace PreservicaConnector\Job;

use Omeka\Job\AbstractJob;
use PreservicaConnector\Preservica\Client;

/**
 * Imports a list of Preservica assets as Omeka items.
 *
 * Arguments: refs (array of asset references), item_set (id or null),
 * comment, query.
 */
class Import extends AbstractJob
{
    /** @var array Dublin Core Terms local name => Omeka property id */
    protected $propertyIds = [];

    public function perform()
    {
        $services = $this->getServiceLocator();
        $api = $services->get('Omeka\ApiManager');
        $logger = $services->get('Omeka\Logger');
        $connection = $services->get('Omeka\Connection');
        $settings = $services->get('Omeka\Settings');
        $client = $services->get(Client::class);

        $refs = (array) $this->getArg('refs', []);
        $itemSetId = $this->getArg('item_set');
        $fallback = (bool) $settings->get('preservicaconnector_fallback_preservation', true);
        $storeReference = (bool) $settings->get('preservicaconnector_store_reference', true);
        $jobId = $this->job->getId();

        $connection->insert('preservica_import', [
            'job_id' => $jobId,
            'query' => $this->getArg('query'),
            'comment' => $this->getArg('comment'),
        ]);

        foreach ($api->search('properties', ['vocabulary_prefix' => 'dcterms'])->getContent() as $property) {
            $this->propertyIds[$property->localName()] = $property->id();
        }

        $counts = ['added_count' => 0, 'skipped_count' => 0, 'failed_count' => 0, 'file_count' => 0];
        foreach ($refs as $ref) {
            if ($this->shouldStop()) {
                $logger->warn('The import was stopped before it finished.'); // @translate
                break;
            }
            $ref = strtolower((string) $ref);
            try {
                $existing = $connection->fetchOne(
                    'SELECT item_id FROM preservica_item WHERE preservica_ref = ?',
                    [$ref]
                );
                if ($existing) {
                    $logger->info(sprintf('Skipped %s: already imported as item #%s.', $ref, $existing));
                    $counts['skipped_count']++;
                    continue;
                }

                $asset = $client->getAsset($ref);
                $files = $client->getFiles($ref, $fallback);

                $itemData = $this->buildValues($asset, $storeReference);
                if ($itemSetId) {
                    $itemData['o:item_set'] = [['o:id' => $itemSetId]];
                }
                $itemData['o:media'] = [];
                foreach ($files as $file) {
                    $itemData['o:media'][] = [
                        'o:ingester' => 'preservica',
                        'preservica_content_path' => $file['contentPath'],
                        'preservica_filename' => $file['filename'],
                        'o:source' => $file['filename'],
                    ];
                }
                if (!$files) {
                    $logger->warn(sprintf('%s has no file to import; importing its metadata only.', $ref));
                }

                $item = $api->create('items', $itemData)->getContent();
                $connection->insert('preservica_item', [
                    'item_id' => $item->id(),
                    'job_id' => $jobId,
                    'preservica_ref' => $ref,
                ]);
                $counts['added_count']++;
                $counts['file_count'] += count($files);
                $logger->info(sprintf('Imported %s as item #%s with %s file(s).', $ref, $item->id(), count($files)));
            } catch (\Omeka\Api\Exception\ValidationException $e) {
                $counts['failed_count']++;
                $logger->err(sprintf('Could not import %s: %s', $ref, json_encode($e->getErrorStore()->getErrors())));
            } catch (\Exception $e) {
                $counts['failed_count']++;
                $logger->err(sprintf('Could not import %s: %s', $ref, $e->getMessage()));
            }
            $connection->update('preservica_import', $counts, ['job_id' => $jobId]);
        }
        $connection->update('preservica_import', $counts, ['job_id' => $jobId]);
    }

    /**
     * Map an asset's Dublin Core metadata to Omeka property values.
     *
     * Both simple (dc:) and qualified (dcterms:) elements map to the Dublin
     * Core Terms property of the same name. The Preservica title and
     * description are used when the metadata has none.
     */
    protected function buildValues(array $asset, $storeReference)
    {
        $dc = $asset['dc'];
        if (empty($dc['title']) && $asset['title'] !== '') {
            $dc['title'] = [$asset['title']];
        }
        if (empty($dc['description']) && $asset['description'] !== '') {
            $dc['description'] = [$asset['description']];
        }
        if ($storeReference) {
            $dc['identifier'][] = $asset['ref'];
        }

        $data = [];
        foreach ($dc as $name => $values) {
            if (!isset($this->propertyIds[$name])) {
                continue;
            }
            foreach ($values as $value) {
                if (preg_match('#^https?://\S+$#i', $value)) {
                    $data['dcterms:' . $name][] = [
                        'type' => 'uri',
                        'property_id' => $this->propertyIds[$name],
                        '@id' => $value,
                    ];
                } else {
                    $data['dcterms:' . $name][] = [
                        'type' => 'literal',
                        'property_id' => $this->propertyIds[$name],
                        '@value' => $value,
                    ];
                }
            }
        }
        return $data;
    }
}
