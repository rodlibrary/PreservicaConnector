<?php
namespace PreservicaConnector;

use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\Mvc\MvcEvent;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Module\AbstractModule;
use PreservicaConnector\Form\ConfigForm;
use PreservicaConnector\Media\IiifLinker;
use PreservicaConnector\Preservica\Client;

class Module extends AbstractModule
{
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function onBootstrap(MvcEvent $event)
    {
        parent::onBootstrap($event);
        // Administrators have access by default; also let editors import.
        $acl = $this->getServiceLocator()->get('Omeka\Acl');
        $acl->allow('editor', 'PreservicaConnector\Controller\Index');
    }

    public function install(ServiceLocatorInterface $serviceLocator)
    {
        $connection = $serviceLocator->get('Omeka\Connection');
        $connection->executeStatement('CREATE TABLE preservica_import (id INT AUTO_INCREMENT NOT NULL, job_id INT NOT NULL, undo_job_id INT DEFAULT NULL, query VARCHAR(255) DEFAULT NULL, comment LONGTEXT DEFAULT NULL, added_count INT DEFAULT 0 NOT NULL, skipped_count INT DEFAULT 0 NOT NULL, failed_count INT DEFAULT 0 NOT NULL, file_count INT DEFAULT 0 NOT NULL, UNIQUE INDEX UNIQ_PRESERVICA_IMPORT_JOB (job_id), INDEX IDX_PRESERVICA_IMPORT_UNDO (undo_job_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $connection->executeStatement('ALTER TABLE preservica_import ADD CONSTRAINT FK_PRESERVICA_IMPORT_JOB FOREIGN KEY (job_id) REFERENCES job (id) ON DELETE CASCADE');
        $connection->executeStatement('ALTER TABLE preservica_import ADD CONSTRAINT FK_PRESERVICA_IMPORT_UNDO FOREIGN KEY (undo_job_id) REFERENCES job (id) ON DELETE SET NULL');
        $connection->executeStatement('CREATE TABLE preservica_item (id INT AUTO_INCREMENT NOT NULL, item_id INT NOT NULL, job_id INT NOT NULL, preservica_ref VARCHAR(36) NOT NULL, UNIQUE INDEX UNIQ_PRESERVICA_ITEM_ITEM (item_id), INDEX IDX_PRESERVICA_ITEM_JOB (job_id), INDEX IDX_PRESERVICA_ITEM_REF (preservica_ref), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $connection->executeStatement('ALTER TABLE preservica_item ADD CONSTRAINT FK_PRESERVICA_ITEM_ITEM FOREIGN KEY (item_id) REFERENCES item (id) ON DELETE CASCADE');
        $connection->executeStatement('ALTER TABLE preservica_item ADD CONSTRAINT FK_PRESERVICA_ITEM_JOB FOREIGN KEY (job_id) REFERENCES job (id) ON DELETE CASCADE');
        $this->installFolderSupport($connection);
        $this->installIiifSupport($connection);
    }

    public function upgrade($oldVersion, $newVersion, ServiceLocatorInterface $serviceLocator)
    {
        $connection = $serviceLocator->get('Omeka\Connection');
        if (version_compare($oldVersion, '0.3.0', '<')) {
            $this->installFolderSupport($connection);
        }
        if (version_compare($oldVersion, '0.4.0', '<')) {
            $this->installIiifSupport($connection);
        }
    }

    /**
     * Schema added in 0.4.0 for linking items to IIIF manifests.
     */
    protected function installIiifSupport($connection)
    {
        $connection->executeStatement('ALTER TABLE preservica_item ADD shared_id VARCHAR(255) DEFAULT NULL, ADD INDEX IDX_PRESERVICA_ITEM_SHARED_ID (shared_id)');
        $connection->executeStatement('ALTER TABLE preservica_import ADD linked_count INT DEFAULT 0 NOT NULL, ADD link_job_id INT DEFAULT NULL');
        $connection->executeStatement('ALTER TABLE preservica_import ADD CONSTRAINT FK_PRESERVICA_IMPORT_LINK FOREIGN KEY (link_job_id) REFERENCES job (id) ON DELETE SET NULL');
    }

    /**
     * Schema added in 0.3.0 for importing whole folders.
     */
    protected function installFolderSupport($connection)
    {
        // What a folder import covered, and the item set it created, if any.
        $connection->executeStatement('ALTER TABLE preservica_import ADD folder_ref VARCHAR(36) DEFAULT NULL, ADD folder_title VARCHAR(255) DEFAULT NULL, ADD include_subfolders TINYINT(1) DEFAULT 0 NOT NULL, ADD created_item_set_id INT DEFAULT NULL');
        // The item set made for each folder, so later imports reuse it.
        $connection->executeStatement('CREATE TABLE preservica_item_set (id INT AUTO_INCREMENT NOT NULL, item_set_id INT NOT NULL, folder_ref VARCHAR(36) NOT NULL, UNIQUE INDEX UNIQ_PRESERVICA_ITEM_SET_ITEM_SET (item_set_id), UNIQUE INDEX UNIQ_PRESERVICA_ITEM_SET_FOLDER (folder_ref), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $connection->executeStatement('ALTER TABLE preservica_item_set ADD CONSTRAINT FK_PRESERVICA_ITEM_SET_ITEM_SET FOREIGN KEY (item_set_id) REFERENCES item_set (id) ON DELETE CASCADE');
    }

    public function uninstall(ServiceLocatorInterface $serviceLocator)
    {
        // Imported items and item sets are ordinary Omeka resources and are kept.
        $connection = $serviceLocator->get('Omeka\Connection');
        $connection->executeStatement('DROP TABLE IF EXISTS preservica_item_set');
        $connection->executeStatement('DROP TABLE IF EXISTS preservica_item');
        $connection->executeStatement('DROP TABLE IF EXISTS preservica_import');

        $settings = $serviceLocator->get('Omeka\Settings');
        foreach (['host', 'username', 'password', 'tenant', 'fallback_preservation', 'store_reference', 'media_mode', 'shared_id_type', 'manifest_url', 'record_url'] as $name) {
            $settings->delete('preservicaconnector_' . $name);
        }
    }

    public function getConfigForm(PhpRenderer $renderer)
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        // Built directly: Omeka wraps this in its own form, which has the CSRF token.
        $form = new ConfigForm;
        $form->init();
        $form->setData([
            'preservicaconnector_host' => $settings->get('preservicaconnector_host'),
            'preservicaconnector_username' => $settings->get('preservicaconnector_username'),
            'preservicaconnector_tenant' => $settings->get('preservicaconnector_tenant'),
            'preservicaconnector_fallback_preservation' => $settings->get('preservicaconnector_fallback_preservation', true),
            'preservicaconnector_store_reference' => $settings->get('preservicaconnector_store_reference', true),
            'preservicaconnector_media_mode' => $settings->get('preservicaconnector_media_mode', IiifLinker::MODE_COPY),
            'preservicaconnector_shared_id_type' => $settings->get('preservicaconnector_shared_id_type'),
            'preservicaconnector_manifest_url' => $settings->get('preservicaconnector_manifest_url'),
            'preservicaconnector_record_url' => $settings->get('preservicaconnector_record_url'),
        ]);
        // The stored password is never sent back to the browser.
        if ($settings->get('preservicaconnector_password')) {
            $form->get('preservicaconnector_password')->setOption(
                'info',
                $renderer->translate('A password is stored. Leave blank to keep it.')
            );
        }
        return $renderer->formCollection($form, false);
    }

    public function handleConfigForm(AbstractController $controller)
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        // Built directly: Omeka wraps this in its own form, which has the CSRF token.
        $form = new ConfigForm;
        $form->init();
        $form->setData($controller->params()->fromPost());
        if (!$form->isValid()) {
            $controller->messenger()->addErrors($form->getMessages());
            return false;
        }
        $data = $form->getData();

        $host = Client::normalizeHost($data['preservicaconnector_host']);
        if ($host === '') {
            $controller->messenger()->addError('The Preservica host is not a valid host name or URL.'); // @translate
            return false;
        }
        $mode = $data['preservicaconnector_media_mode'] === IiifLinker::MODE_IIIF ? IiifLinker::MODE_IIIF : IiifLinker::MODE_COPY;
        $idType = trim((string) $data['preservicaconnector_shared_id_type']);
        $manifestUrl = trim((string) $data['preservicaconnector_manifest_url']);
        $recordUrl = trim((string) $data['preservicaconnector_record_url']);
        $errors = [];
        if ($manifestUrl !== '' && !IiifLinker::isValidPattern($manifestUrl)) {
            $errors[] = 'The IIIF manifest URL pattern must be a web address containing {id}.'; // @translate
        }
        if ($recordUrl !== '' && !IiifLinker::isValidPattern($recordUrl)) {
            $errors[] = 'The discovery record URL pattern must be a web address containing {id}.'; // @translate
        }
        if ($mode === IiifLinker::MODE_IIIF && ($idType === '' || $manifestUrl === '')) {
            $errors[] = 'To link to IIIF manifests, set the shared identifier type and the IIIF manifest URL pattern.'; // @translate
        }
        if ($errors) {
            foreach ($errors as $error) {
                $controller->messenger()->addError($error);
            }
            return false;
        }

        $settings->set('preservicaconnector_media_mode', $mode);
        $settings->set('preservicaconnector_shared_id_type', $idType);
        $settings->set('preservicaconnector_manifest_url', $manifestUrl);
        $settings->set('preservicaconnector_record_url', $recordUrl);
        $settings->set('preservicaconnector_host', $host);
        $settings->set('preservicaconnector_username', trim($data['preservicaconnector_username']));
        $settings->set('preservicaconnector_tenant', trim((string) $data['preservicaconnector_tenant']));
        if ((string) $data['preservicaconnector_password'] !== '') {
            $settings->set('preservicaconnector_password', $data['preservicaconnector_password']);
        }
        $settings->set('preservicaconnector_fallback_preservation', (bool) $data['preservicaconnector_fallback_preservation']);
        $settings->set('preservicaconnector_store_reference', (bool) $data['preservicaconnector_store_reference']);

        // Check the connection so that mistakes show up now, not at import.
        $client = $services->build(Client::class);
        try {
            $client->getToken(true);
            $controller->messenger()->addSuccess('Connected to Preservica successfully.'); // @translate
        } catch (\Exception $e) {
            $controller->messenger()->addWarning(
                $controller->translate('The settings were saved, but the connection test failed:') . ' ' . $e->getMessage()
            );
        }
        return true;
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        // Lets "Past imports" link to the items created by one import:
        // /admin/item?preservica_import_job=<job id>
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.search.query',
            [$this, 'filterItemsByImport']
        );
    }

    public function filterItemsByImport(Event $event)
    {
        $query = $event->getParam('request')->getContent();
        if (empty($query['preservica_import_job'])) {
            return;
        }
        $connection = $this->getServiceLocator()->get('Omeka\Connection');
        $itemIds = $connection->fetchFirstColumn(
            'SELECT item_id FROM preservica_item WHERE job_id = ?',
            [(int) $query['preservica_import_job']]
        );
        $qb = $event->getParam('queryBuilder');
        $adapter = $event->getTarget();
        $qb->andWhere($qb->expr()->in(
            'omeka_root.id',
            $adapter->createNamedParameter($qb, $itemIds ?: [0])
        ));
    }
}
