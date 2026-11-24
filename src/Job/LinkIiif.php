<?php
namespace PreservicaConnector\Job;

use Omeka\Job\AbstractJob;
use PreservicaConnector\Media\IiifLinker;
use PreservicaConnector\Preservica\Client;

/**
 * Switches the items created by one import from copied files to IIIF links:
 * for each item, adds the asset's IIIF manifest on the discovery platform as
 * a media, then deletes the files that were copied from Preservica. The item
 * itself is kept, so exhibit pages that feature it keep working.
 *
 * An item whose manifest cannot be added keeps its copied files.
 *
 * Arguments: import_job (id of the import job whose items to switch).
 */
class LinkIiif extends AbstractJob
{
    public function perform()
    {
        $services = $this->getServiceLocator();
        $api = $services->get('Omeka\ApiManager');
        $logger = $services->get('Omeka\Logger');
        $connection = $services->get('Omeka\Connection');
        $linker = IiifLinker::fromSettings($services->get(Client::class), $services->get('Omeka\Settings'));

        $rows = $connection->fetchAllAssociative(
            'SELECT id, item_id, preservica_ref, shared_id FROM preservica_item WHERE job_id = ? ORDER BY id',
            [(int) $this->getArg('import_job')]
        );
        $counts = ['linked' => 0, 'already' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            if ($this->shouldStop()) {
                $logger->warn('Stopped before all items were switched.'); // @translate
                break;
            }
            $itemId = (int) $row['item_id'];
            try {
                $links = $linker->linksFor($row['preservica_ref'], $row['shared_id']);
                $item = $api->read('items', $itemId)->getContent();

                $copied = [];
                $linked = false;
                foreach ($item->media() as $media) {
                    if ($media->ingester() === 'preservica') {
                        $copied[] = $media->id();
                    } elseif (in_array($media->ingester(), ['preservica_iiif', 'iiif_presentation']) && $media->source() === $links['manifest']) {
                        $linked = true;
                    }
                }
                if ($linked && !$copied) {
                    $counts['already']++;
                    $logger->info(sprintf('Item #%s already links to %s.', $itemId, $links['manifest']));
                    continue;
                }

                // Add the manifest first: if that fails, the copies stay.
                if (!$linked) {
                    $api->create('media', [
                        'o:item' => ['o:id' => $itemId],
                        'o:ingester' => 'preservica_iiif',
                        'o:source' => $links['manifest'],
                    ]);
                }
                foreach ($copied as $mediaId) {
                    $api->delete('media', $mediaId);
                }

                // Record the shared identifier and discovery link on the item.
                $dc = [];
                foreach (['identifier', 'relation'] as $term) {
                    foreach ($item->value('dcterms:' . $term, ['all' => true]) as $value) {
                        $dc[$term][] = $value->uri() ? ['uri' => $value->uri(), 'label' => (string) $value->value()] : (string) $value->value();
                    }
                }
                $before = $dc;
                Import::addLinkValues($dc, $links);
                $new = $this->newValues($api, $before, $dc);
                if ($new) {
                    $api->update('items', $itemId, $new, [], ['isPartial' => true, 'collectionAction' => 'append']);
                }
                $connection->update('preservica_item', ['shared_id' => $links['id']], ['id' => $row['id']]);

                $counts['linked']++;
                $logger->info(sprintf(
                    'Item #%s now links to %s; removed %s copied file(s).',
                    $itemId,
                    $links['manifest'],
                    count($copied)
                ));
            } catch (\Omeka\Api\Exception\ValidationException $e) {
                $counts['failed']++;
                $logger->err(sprintf('Could not switch item #%s (%s); its files were kept: %s', $itemId, $row['preservica_ref'], json_encode($e->getErrorStore()->getErrors())));
            } catch (\Exception $e) {
                $counts['failed']++;
                $logger->err(sprintf('Could not switch item #%s (%s); its files were kept: %s', $itemId, $row['preservica_ref'], $e->getMessage()));
            }
        }
        $logger->info(sprintf(
            'Switched %s item(s) to IIIF links; %s already linked; %s failed.',
            $counts['linked'],
            $counts['already'],
            $counts['failed']
        ));
    }

    /**
     * Values present in $after but not in $before, as API data.
     */
    protected function newValues($api, array $before, array $after)
    {
        $data = [];
        foreach ($after as $term => $values) {
            $old = isset($before[$term]) ? $before[$term] : [];
            foreach (array_slice($values, count($old)) as $value) {
                $propertyId = $api->search('properties', ['term' => 'dcterms:' . $term])->getContent()[0]->id();
                $data['dcterms:' . $term][] = is_array($value)
                    ? ['type' => 'uri', 'property_id' => $propertyId, '@id' => $value['uri'], 'o:label' => $value['label']]
                    : ['type' => 'literal', 'property_id' => $propertyId, '@value' => $value];
            }
        }
        return $data;
    }
}
