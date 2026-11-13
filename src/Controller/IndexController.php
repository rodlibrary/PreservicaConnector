<?php
namespace PreservicaConnector\Controller;

use Doctrine\DBAL\Connection;
use Laminas\Form\Form;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Omeka\Stdlib\Message;
use PreservicaConnector\Form\ImportForm;
use PreservicaConnector\Job\Import;
use PreservicaConnector\Job\Undo;
use PreservicaConnector\Preservica\Client;

class IndexController extends AbstractActionController
{
    const PER_PAGE_OPTIONS = [25, 50, 100];

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
            $row['undo_job'] = $row['undo_job_id']
                ? $this->api()->read('jobs', $row['undo_job_id'])->getContent()
                : null;
            $imports[] = $row;
        }
        $this->paginator($total, $page, $perPage);
        return new ViewModel([
            'imports' => $imports,
            'form' => $form,
        ]);
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
