<?php
namespace PreservicaConnector\Job;

use Omeka\Job\AbstractJob;
use PreservicaConnector\Preservica\Client;

/**
 * Imports Preservica assets as Omeka items: either a list of assets, or
 * every asset in a folder (and, optionally, its subfolders).
 *
 * Arguments:
 * + refs: array of asset references, or
 * + folder: a folder reference, with recursive (bool, default true)
 * + item_set: an item set id, "folder" for the item set made for the folder
 *   (created on first use, reused by later imports of the same folder), or
 *   null for none
 * + comment, query
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

        $this->api = $api;
        $this->connection = $connection;
        $refs = (array) $this->getArg('refs', []);
        $folderRef = $this->getArg('folder');
        $recursive = (bool) $this->getArg('recursive', true);
        $itemSetArg = $this->getArg('item_set');
        $fallback = (bool) $settings->get('preservicaconnector_fallback_preservation', true);
        $storeReference = (bool) $settings->get('preservicaconnector_store_reference', true);
        $jobId = $this->job->getId();

        $connection->insert('preservica_import', [
            'job_id' => $jobId,
            'query' => $this->getArg('query'),
            'comment' => $this->getArg('comment'),
            'folder_ref' => $folderRef,
            'include_subfolders' => $folderRef && $recursive ? 1 : 0,
        ]);

        foreach ($api->search('properties', ['vocabulary_prefix' => 'dcterms'])->getContent() as $property) {
            $this->propertyIds[$property->localName()] = $property->id();
        }

        if ($folderRef) {
            // Fails the job early if the folder cannot be read.
            $folder = $client->getFolder($folderRef);
            $connection->update(
                'preservica_import',
                ['folder_title' => mb_substr($folder['title'], 0, 255)],
                ['job_id' => $jobId]
            );
            $logger->info(sprintf(
                'Importing the assets in folder "%s" (%s)%s.',
                $folder['title'],
                $folder['ref'],
                $recursive ? ' and its subfolders' : ''
            ));
            $refs = $client->walkFolderAssets($folder['ref'], $recursive);
        }

        // The item set is resolved when the first item needs it, so that an
        // empty folder does not leave an empty item set behind.
        $itemSetId = null;
        $getItemSetId = function () use (&$itemSetId, $itemSetArg, $folderRef, &$folder, $storeReference, $jobId, $logger) {
            if ($itemSetId === null) {
                if ($itemSetArg === 'folder' && $folderRef) {
                    $itemSetId = $this->folderItemSet($folder, $storeReference, $jobId, $logger);
                } else {
                    $itemSetId = $itemSetArg ? (int) $itemSetArg : 0;
                }
            }
            return $itemSetId;
        };

        $counts = ['added_count' => 0, 'skipped_count' => 0, 'failed_count' => 0, 'file_count' => 0];
        try {
            $this->importAll($refs, $getItemSetId, $client, $fallback, $storeReference, $jobId, $counts);
        } catch (\Exception $e) {
            // Listing the folder failed part way; keep what was imported.
            $logger->err(sprintf('The import stopped: %s', $e->getMessage()));
            $connection->update('preservica_import', $counts, ['job_id' => $jobId]);
            throw $e;
        }
        $connection->update('preservica_import', $counts, ['job_id' => $jobId]);
    }

    /** @var \Omeka\Api\Manager */
    protected $api;

    /** @var \Doctrine\DBAL\Connection */
    protected $connection;

    /**
     * @param iterable $refs Asset references
     * @param callable $getItemSetId Returns the item set id, or 0 for none
     */
    protected function importAll($refs, callable $getItemSetId, Client $client, $fallback, $storeReference, $jobId, array &$counts)
    {
        $api = $this->api;
        $connection = $this->connection;
        $logger = $this->getServiceLocator()->get('Omeka\Logger');
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
                    $counts['skipped_count']++;
                    $itemSetId = $getItemSetId();
                    if ($itemSetId && $this->addToItemSet((int) $existing, $itemSetId)) {
                        $logger->info(sprintf('%s was already imported as item #%s; added it to item set #%s.', $ref, $existing, $itemSetId));
                    } else {
                        $logger->info(sprintf('Skipped %s: already imported as item #%s.', $ref, $existing));
                    }
                    $connection->update('preservica_import', $counts, ['job_id' => $jobId]);
                    continue;
                }

                $asset = $client->getAsset($ref);
                $files = $client->getFiles($ref, $fallback);

                $itemData = $this->buildValues($asset, $storeReference);
                $itemSetId = $getItemSetId();
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
    }

    /**
     * Find or create the item set for a folder.
     *
     * @return int Item set id
     */
    protected function folderItemSet(array $folder, $storeReference, $jobId, $logger)
    {
        $itemSetId = (int) $this->connection->fetchOne(
            'SELECT pis.item_set_id FROM preservica_item_set pis JOIN item_set ON item_set.id = pis.item_set_id WHERE pis.folder_ref = ?',
            [$folder['ref']]
        );
        if ($itemSetId) {
            $logger->info(sprintf('Adding items to the existing item set #%s for this folder.', $itemSetId));
            return $itemSetId;
        }
        $data = $this->buildValues([
            'ref' => $folder['ref'],
            'title' => $folder['title'] !== '' ? $folder['title'] : $folder['ref'],
            'description' => $folder['description'],
            'dc' => [],
        ], $storeReference);
        $itemSet = $this->api->create('item_sets', $data)->getContent();
        $this->connection->insert('preservica_item_set', [
            'item_set_id' => $itemSet->id(),
            'folder_ref' => $folder['ref'],
        ]);
        $this->connection->update(
            'preservica_import',
            ['created_item_set_id' => $itemSet->id()],
            ['job_id' => $jobId]
        );
        $logger->info(sprintf('Created item set #%s "%s" for this folder.', $itemSet->id(), $itemSet->displayTitle()));
        return $itemSet->id();
    }

    /**
     * Add an existing item to an item set.
     *
     * @return bool False if it was already in the set
     */
    protected function addToItemSet($itemId, $itemSetId)
    {
        $inSet = $this->connection->fetchOne(
            'SELECT 1 FROM item_item_set WHERE item_id = ? AND item_set_id = ?',
            [$itemId, $itemSetId]
        );
        if ($inSet) {
            return false;
        }
        $this->api->update(
            'items',
            $itemId,
            ['o:item_set' => [['o:id' => $itemSetId]]],
            [],
            ['isPartial' => true, 'collectionAction' => 'append']
        );
        return true;
    }

    /**
     * Map an asset's descriptive metadata to Omeka property values.
     *
     * Simple (dc:) and qualified (dcterms:) Dublin Core elements, and MODS
     * records crosswalked by the client, map to the Dublin Core Terms
     * property of the same name. The Preservica title and
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
                if (is_array($value)) {
                    // A linked value: authority URI with its label.
                    $data['dcterms:' . $name][] = [
                        'type' => 'uri',
                        'property_id' => $this->propertyIds[$name],
                        '@id' => $value['uri'],
                        'o:label' => $value['label'],
                    ];
                } elseif (preg_match('#^https?://\S+$#i', $value)) {
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
