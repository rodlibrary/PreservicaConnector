<?php
namespace PreservicaConnector\Job;

use Omeka\Job\AbstractJob;

/**
 * Deletes the Omeka items (and their files) created by one import. Nothing
 * is changed in Preservica.
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
    }
}
