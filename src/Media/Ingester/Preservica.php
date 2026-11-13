<?php
namespace PreservicaConnector\Media\Ingester;

use Laminas\Form\Element\Text;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Request;
use Omeka\Entity\Media;
use Omeka\File\TempFileFactory;
use Omeka\Media\Ingester\IngesterInterface;
use Omeka\Stdlib\ErrorStore;
use PreservicaConnector\Preservica\Client;

/**
 * Copies a file out of Preservica and stores it as an ordinary Omeka file.
 */
class Preservica implements IngesterInterface
{
    protected $client;

    protected $tempFileFactory;

    protected $fallbackToPreservation;

    public function __construct(Client $client, TempFileFactory $tempFileFactory, $fallbackToPreservation = true)
    {
        $this->client = $client;
        $this->tempFileFactory = $tempFileFactory;
        $this->fallbackToPreservation = $fallbackToPreservation;
    }

    public function getLabel()
    {
        return 'Preservica'; // @translate
    }

    public function getRenderer()
    {
        return 'file';
    }

    /**
     * Accepts one of these non-prefixed keys:
     *
     * + preservica_content_path: the bitstream content path of one file, as
     *   returned by Client::getFiles() (used by the import job), with an
     *   optional preservica_filename.
     * + preservica_ref: the reference (UUID) of an asset; its first file is
     *   ingested (used by the "add media" form).
     *
     * {@inheritDoc}
     */
    public function ingest(Media $media, Request $request, ErrorStore $errorStore)
    {
        $data = $request->getContent();
        try {
            if (!empty($data['preservica_content_path'])) {
                $contentPath = $data['preservica_content_path'];
                $filename = !empty($data['preservica_filename']) ? $data['preservica_filename'] : basename(dirname($contentPath));
            } elseif (!empty($data['preservica_ref'])) {
                $ref = trim($data['preservica_ref']);
                $files = $this->client->getFiles($ref, $this->fallbackToPreservation);
                if (!$files) {
                    $errorStore->addError('preservica_ref', 'This Preservica asset has no file that can be imported.'); // @translate
                    return;
                }
                $contentPath = $files[0]['contentPath'];
                $filename = $files[0]['filename'];
            } else {
                $errorStore->addError('error', 'No Preservica asset reference specified'); // @translate
                return;
            }

            $tempFile = $this->tempFileFactory->build();
            try {
                $this->client->download($contentPath, $tempFile->getTempPath());
            } catch (\Exception $e) {
                $tempFile->delete();
                throw $e;
            }
        } catch (\Exception $e) {
            $errorStore->addError('preservica', $e->getMessage());
            return;
        }

        $tempFile->setSourceName($filename);
        if (!array_key_exists('o:source', $data)) {
            $media->setSource($filename);
        }
        $tempFile->mediaIngestFile($media, $request, $errorStore);
    }

    public function form(PhpRenderer $view, array $options = [])
    {
        $input = new Text('o:media[__index__][preservica_ref]');
        $input->setOptions([
            'label' => 'Preservica asset reference', // @translate
            'info' => 'The reference (UUID) of a Preservica asset. Its access file is copied into Omeka.', // @translate
        ]);
        $input->setAttributes([
            'id' => 'media-preservica-ref-__index__',
            'required' => true,
            'placeholder' => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
        ]);
        return $view->formRow($input);
    }
}
