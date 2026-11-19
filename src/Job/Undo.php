<?php
namespace PreservicaConnector\Job;

use Omeka\Job\AbstractJob;

/**
 * Deletes the Omeka items (and their files) created by one import, and the
 * folder item set it created if that is now empty. Items that the import only
 * added to an item set are left alone. Nothing is changed in Preservica.
 *
 * Arguments: import_job (id of the import job to undo).
 */
class Undo extends AbstractJob
{
    public function perform()
    {
        $services = $this->getServiceLocator();
        $api = $services->get('Omeka\ApiManager');
        $logger = $services->get('Omeka\Logger');
        $connection = $services->get('Omeka\Connection');

        $itemIds = $connection->fetchFirstColumn(
            'SELECT item_id FROM preservica_item WHERE job_id = ?',
            [(int) $this->getArg('import_job')]
        );
        $deleted = 0;
        foreach ($itemIds as $itemId) {
            if ($this->shouldStop()) {
                break;
            }
            try {
                // The preservica_item row goes with the item (ON DELETE CASCADE).
                $api->delete('items', $itemId);
                $deleted++;
            } catch (\Exception $e) {
                $logger->err(sprintf('Could not delete item #%s: %s', $itemId, $e->getMessage()));
            }
        }
        $logger->info(sprintf('Deleted %s item(s).', $deleted));

        // Remove the folder item set this import created, if nothing else
        // has been put in it since.
        $itemSetId = (int) $connection->fetchOne(
            'SELECT created_item_set_id FROM preservica_import WHERE job_id = ?',
            [(int) $this->getArg('import_job')]
        );
        if ($itemSetId && !$this->shouldStop()) {
            $remaining = $connection->fetchOne('SELECT COUNT(*) FROM item_item_set WHERE item_set_id = ?', [$itemSetId]);
            $exists = $connection->fetchOne('SELECT 1 FROM item_set WHERE id = ?', [$itemSetId]);
            if ($exists && !$remaining) {
                $api->delete('item_sets', $itemSetId);
                $logger->info(sprintf('Deleted item set #%s, which this import created.', $itemSetId));
            } elseif ($exists) {
                $logger->info(sprintf('Kept item set #%s: it still has %s item(s).', $itemSetId, $remaining));
            }
        }
    }
}
