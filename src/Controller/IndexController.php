<?php
namespace PreservicaConnector\Controller;

use Doctrine\DBAL\Connection;
use Laminas\Form\Form;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Omeka\Stdlib\Message;
use PreservicaConnector\Form\FolderImportForm;
use PreservicaConnector\Form\ImportForm;
use PreservicaConnector\Job\Import;
use PreservicaConnector\Job\LinkIiif;
use PreservicaConnector\Job\Undo;
use PreservicaConnector\Media\IiifLinker;
use PreservicaConnector\Preservica\Client;

class IndexController extends AbstractActionController
{
    const PER_PAGE_OPTIONS = [25, 50, 100];

    const BROWSE_PER_PAGE = 100;

    protected $client;

    protected $connection;

    public function __construct(Client $client, Connection $connection)
    {
        $this->client = $client;
        $this->connection = $connection;
    }

    /**
     * Search Preservica and show the results for selection.
     */
    public function indexAction()
    {
        $query = trim((string) $this->params()->fromQuery('q', ''));
        $page = max(1, (int) $this->params()->fromQuery('page', 1));
        $perPage = (int) $this->params()->fromQuery('per_page', self::PER_PAGE_OPTIONS[0]);
        if (!in_array($perPage, self::PER_PAGE_OPTIONS)) {
            $perPage = self::PER_PAGE_OPTIONS[0];
        }

        $view = new ViewModel([
            'configured' => $this->client->isConfigured(),
            'host' => $this->client->getBaseUrl(),
            'query' => $query,
            'perPage' => $perPage,
            'perPageOptions' => self::PER_PAGE_OPTIONS,
            'searched' => false,
            'results' => [],
            'total' => 0,
            'imported' => [],
            'importForm' => null,
        ]);
        if ($query === '' || !$this->client->isConfigured()) {
            return $view;
        }

        try {
            $search = $this->client->searchAssets($query, ($page - 1) * $perPage, $perPage);
        } catch (\Exception $e) {
            $this->messenger()->addError($e->getMessage());
            return $view;
        }

        // Which of these assets are already in Omeka?
        $imported = [];
        $refs = array_column($search['results'], 'ref');
        if ($refs) {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT preservica_ref, item_id FROM preservica_item WHERE preservica_ref IN (?)',
                [$refs],
                [Connection::PARAM_STR_ARRAY]
            );
            foreach ($rows as $row) {
                $imported[$row['preservica_ref']] = (int) $row['item_id'];
            }
        }

        $importForm = $this->getForm(ImportForm::class);
        $importForm->setAttribute('action', $this->url()->fromRoute('admin/preservica-connector/import'));
        $importForm->setAttribute('id', 'preservica-import-form');
        $importForm->get('query')->setValue($query);

        $this->paginator($search['total'], $page, $perPage);
        $view->setVariables([
            'searched' => true,
            'results' => $search['results'],
            'total' => $search['total'],
            'imported' => $imported,
            'importForm' => $importForm,
        ]);
        return $view;
    }

    /**
     * Browse the Preservica folder tree, to import a folder or pick assets.
     */
    public function browseAction()
    {
        $folderRef = strtolower(trim((string) $this->params()->fromQuery('folder', '')));
        if ($folderRef !== '' && !preg_match(Client::REF_PATTERN, $folderRef)) {
            $folderRef = '';
        }
        $page = max(1, (int) $this->params()->fromQuery('page', 1));

        $view = new ViewModel([
            'configured' => $this->client->isConfigured(),
            'host' => $this->client->getBaseUrl(),
            'path' => [],
            'folder' => null,
            'children' => null,
            'imported' => [],
            'importForm' => null,
            'folderForm' => null,
            'folderItemSet' => null,
        ]);
        if (!$this->client->isConfigured()) {
            return $view;
        }

        try {
            $path = $folderRef !== '' ? $this->client->getFolderPath($folderRef) : [];
            $children = $this->client->listChildren(
                $folderRef !== '' ? $folderRef : null,
                ($page - 1) * self::BROWSE_PER_PAGE,
                self::BROWSE_PER_PAGE
            );
        } catch (\Exception $e) {
            $this->messenger()->addError($e->getMessage());
            return $view;
        }
        $folder = $path ? end($path) : null;

        $refs = array_column($children['assets'], 'ref');
        $imported = [];
        if ($refs) {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT preservica_ref, item_id FROM preservica_item WHERE preservica_ref IN (?)',
                [$refs],
                [Connection::PARAM_STR_ARRAY]
            );
            foreach ($rows as $row) {
                $imported[$row['preservica_ref']] = (int) $row['item_id'];
            }
        }

        $importForm = null;
        if ($children['assets']) {
            $importForm = $this->getForm(ImportForm::class);
            $importForm->setAttribute('action', $this->url()->fromRoute('admin/preservica-connector/import'));
            $importForm->setAttribute('id', 'preservica-import-form');
            $importForm->get('query')->setValue($folder ? sprintf($this->translate('Folder: %s'), $folder['title']) : '');
        }

        $folderForm = null;
        $folderItemSet = null;
        if ($folder) {
            $folderForm = $this->getForm(FolderImportForm::class);
            $folderForm->setAttribute('action', $this->url()->fromRoute('admin/preservica-connector/import-folder'));
            $folderForm->setAttribute('id', 'preservica-folder-form');
            $folderForm->get('folder')->setValue($folder['ref']);
            $itemSetId = $this->connection->fetchOne(
                'SELECT pis.item_set_id FROM preservica_item_set pis JOIN item_set ON item_set.id = pis.item_set_id WHERE pis.folder_ref = ?',
                [$folder['ref']]
            );
            if ($itemSetId) {
                $folderItemSet = $this->api()->read('item_sets', $itemSetId)->getContent();
            }
            $title = $folder['title'] !== '' ? $folder['title'] : $this->translate('[Untitled]');
            $element = $folderForm->get('item_set');
            $element->setOption('prepend_value_options', [
                'folder' => $folderItemSet
                    ? sprintf($this->translate('This folder\'s item set: %s'), $folderItemSet->displayTitle())
                    : sprintf($this->translate('New item set: %s'), $title),
                '' => $this->translate('No item set'),
            ]);
        }

        $this->paginator($children['total'], $page, self::BROWSE_PER_PAGE);
        $view->setVariables([
            'path' => $path,
            'folder' => $folder,
            'children' => $children,
            'imported' => $imported,
            'importForm' => $importForm,
            'folderForm' => $folderForm,
            'folderItemSet' => $folderItemSet,
        ]);
        return $view;
    }

    /**
     * Start a background job that imports every asset in a folder.
     */
    public function importFolderAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute('admin/preservica-connector/browse');
        }
        $form = $this->getForm(FolderImportForm::class);
        $form->setData($this->params()->fromPost());
        if (!$form->isValid()) {
            $this->messenger()->addFormErrors($form);
            return $this->redirect()->toRoute('admin/preservica-connector/browse');
        }
        $data = $form->getData();
        $folderRef = strtolower((string) $data['folder']);
        if (!preg_match(Client::REF_PATTERN, $folderRef)) {
            $this->messenger()->addError('Choose a folder to import.'); // @translate
            return $this->redirect()->toRoute('admin/preservica-connector/browse');
        }
        $itemSet = (string) $data['item_set'];
        if ($itemSet !== 'folder') {
            $itemSet = $itemSet !== '' ? (int) $itemSet : null;
        }

        $job = $this->jobDispatcher()->dispatch(Import::class, [
            'folder' => $folderRef,
            'recursive' => (bool) $data['recursive'],
            'item_set' => $itemSet,
            'comment' => trim((string) $data['comment']),
        ]);
        $message = new Message(
            'Importing a Preservica folder in %s', // @translate
            sprintf(
                '<a href="%s">%s</a>',
                htmlspecialchars($this->url()->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId()])),
                sprintf($this->translate('job #%s'), $job->getId())
            )
        );
        $message->setEscapeHtml(false);
        $this->messenger()->addSuccess($message);
        return $this->redirect()->toRoute('admin/preservica-connector/past-imports');
    }

    /**
     * Start a background job that imports the selected assets.
     */
    public function importAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute('admin/preservica-connector');
        }
        $post = $this->params()->fromPost();
        $form = $this->getForm(ImportForm::class);
        $form->setData($post);
        if (!$form->isValid()) {
            $this->messenger()->addFormErrors($form);
            return $this->redirect()->toRoute('admin/preservica-connector');
        }
        $data = $form->getData();

        $refs = [];
        foreach (isset($post['refs']) && is_array($post['refs']) ? $post['refs'] : [] as $ref) {
            if (is_string($ref) && preg_match(Client::REF_PATTERN, $ref)) {
                $refs[strtolower($ref)] = true;
            }
        }
        $refs = array_keys($refs);
        if (!$refs) {
            $this->messenger()->addError('Select at least one asset to import.'); // @translate
            return $this->redirect()->toRoute(
                'admin/preservica-connector',
                [],
                ['query' => ['q' => (string) $data['query']]]
            );
        }

        $job = $this->jobDispatcher()->dispatch(Import::class, [
            'refs' => $refs,
            'item_set' => $data['item_set'] ? (int) $data['item_set'] : null,
            'comment' => trim((string) $data['comment']),
            'query' => mb_substr((string) $data['query'], 0, 255),
        ]);
        $message = new Message(
            'Importing %1$s Preservica asset(s) in %2$s', // @translate
            count($refs),
            sprintf(
                '<a href="%s">%s</a>',
                htmlspecialchars($this->url()->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId()])),
                sprintf($this->translate('job #%s'), $job->getId())
            )
        );
        $message->setEscapeHtml(false);
        $this->messenger()->addSuccess($message);
        return $this->redirect()->toRoute('admin/preservica-connector/past-imports');
    }

    /**
     * List imports, and undo the ones selected.
     */
    public function pastImportsAction()
    {
        $form = $this->getForm(Form::class);
        $form->setAttribute('id', 'preservica-undo-form');

        if ($this->getRequest()->isPost()) {
            $post = $this->params()->fromPost();
            $form->setData($post);
            if ($form->isValid()) {
                $jobIds = isset($post['undo']) && is_array($post['undo']) ? array_map('intval', $post['undo']) : [];
                if (isset($post['action']) && $post['action'] === 'link') {
                    $this->dispatchLinkJobs($jobIds);
                    return $this->redirect()->toRoute('admin/preservica-connector/past-imports');
                }
                foreach ($jobIds as $jobId) {
                    $import = $this->connection->fetchAssociative(
                        'SELECT id FROM preservica_import WHERE job_id = ? AND undo_job_id IS NULL',
                        [$jobId]
                    );
                    if (!$import) {
                        continue;
                    }
                    $undoJob = $this->jobDispatcher()->dispatch(Undo::class, ['import_job' => $jobId]);
                    $this->connection->update(
                        'preservica_import',
                        ['undo_job_id' => $undoJob->getId()],
                        ['id' => $import['id']]
                    );
                    $this->messenger()->addSuccess(new Message('Undoing import job #%s', $jobId)); // @translate
                }
                if (!$jobIds) {
                    $this->messenger()->addError('Select at least one import to undo.'); // @translate
                }
            } else {
                $this->messenger()->addFormErrors($form);
            }
            return $this->redirect()->toRoute('admin/preservica-connector/past-imports');
        }

        $page = max(1, (int) $this->params()->fromQuery('page', 1));
        $perPage = 25;
        $total = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM preservica_import');
        $rows = $this->connection->fetchAllAssociative(sprintf(
            'SELECT pi.*, (SELECT COUNT(*) FROM preservica_item pit WHERE pit.job_id = pi.job_id) AS item_count
            FROM preservica_import pi ORDER BY pi.id DESC LIMIT %d OFFSET %d',
            $perPage,
            ($page - 1) * $perPage
        ));
        $imports = [];
        foreach ($rows as $row) {
            $row['job'] = $this->api()->read('jobs', $row['job_id'])->getContent();
            $row['link_job'] = $row['link_job_id']
                ? $this->api()->read('jobs', $row['link_job_id'])->getContent()
                : null;
            $row['undo_job'] = $row['undo_job_id']
                ? $this->api()->read('jobs', $row['undo_job_id'])->getContent()
                : null;
            $imports[] = $row;
        }
        $this->paginator($total, $page, $perPage);
        return new ViewModel([
            'imports' => $imports,
            'form' => $form,
            'iiifEnabled' => $this->settings()->get('preservicaconnector_media_mode') === IiifLinker::MODE_IIIF,
        ]);
    }

    /**
     * Start jobs that switch the items of the given imports to IIIF links.
     *
     * @param int[] $jobIds Import job ids
     */
    protected function dispatchLinkJobs(array $jobIds)
    {
        if ($this->settings()->get('preservicaconnector_media_mode') !== IiifLinker::MODE_IIIF) {
            $this->messenger()->addError('Turn on "Link to IIIF manifests" in the module configuration first.'); // @translate
            return;
        }
        if (!$jobIds) {
            $this->messenger()->addError('Select at least one import to switch to IIIF links.'); // @translate
            return;
        }
        foreach ($jobIds as $jobId) {
            $import = $this->connection->fetchAssociative(
                'SELECT id FROM preservica_import WHERE job_id = ? AND undo_job_id IS NULL',
                [$jobId]
            );
            if (!$import) {
                continue;
            }
            $linkJob = $this->jobDispatcher()->dispatch(LinkIiif::class, ['import_job' => $jobId]);
            $this->connection->update('preservica_import', ['link_job_id' => $linkJob->getId()], ['id' => $import['id']]);
            $this->messenger()->addSuccess(new Message('Switching the items of import job #%1$s to IIIF links in job #%2$s', $jobId, $linkJob->getId())); // @translate
        }
    }

    /**
     * Relay an asset thumbnail, which needs the Preservica access token.
     */
    public function thumbnailAction()
    {
        $response = $this->getResponse();
        $thumbnail = $this->client->isConfigured()
            ? $this->client->getThumbnail($this->params('ref'))
            : null;
        if (!$thumbnail) {
            $response->setStatusCode(404);
            return $response;
        }
        $response->getHeaders()
            ->addHeaderLine('Content-Type', $thumbnail['type'])
            ->addHeaderLine('Cache-Control', 'private, max-age=3600')
            ->addHeaderLine('X-Content-Type-Options', 'nosniff');
        $response->setContent($thumbnail['body']);
        return $response;
    }
}
