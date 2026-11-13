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
    }

    public function uninstall(ServiceLocatorInterface $serviceLocator)
    {
        // Imported items are ordinary Omeka items and are kept.
        $connection = $serviceLocator->get('Omeka\Connection');
        $connection->executeStatement('DROP TABLE IF EXISTS preservica_item');
        $connection->executeStatement('DROP TABLE IF EXISTS preservica_import');

        $settings = $serviceLocator->get('Omeka\Settings');
        foreach (['host', 'username', 'password', 'tenant', 'fallback_preservation', 'store_reference'] as $name) {
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
