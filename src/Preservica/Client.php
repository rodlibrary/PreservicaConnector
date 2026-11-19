<?php
namespace PreservicaConnector\Preservica;

use ArrayAccess;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Laminas\Http\Client as HttpClient;
use Laminas\Http\Response;
use PreservicaConnector\Metadata\ModsMapper;

/**
 * Minimal read-only client for the Preservica Access Token, Content and
 * Entity APIs.
 *
 * XML responses are read by element local name so that the client does not
 * depend on the version-specific XIP/Entity namespaces (v6.x, v7.x, ...).
 */
class Client
{
    const REF_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    const CONTENT_PATH_PATTERN = '#^/api/entity/content-objects/[0-9a-f-]{36}/generations/\d+/bitstreams/\d+/content$#i';

    /** Re-login after this many seconds (Preservica tokens last 15 minutes). */
    const TOKEN_LIFETIME = 600;

    /** @var callable Returns a fresh Laminas HTTP client. */
    protected $httpClientFactory;

    /** @var string e.g. https://us.preservica.com */
    protected $baseUrl;

    protected $username;

    protected $password;

    protected $tenant;

    /** @var ArrayAccess|array Token cache with keys "token", "time", "key". */
    protected $tokenStore;

    /**
     * @param callable $httpClientFactory
     * @param string $host Host name or base URL
     * @param string $username
     * @param string $password
     * @param string|null $tenant
     * @param ArrayAccess|null $tokenStore Where to cache the access token
     */
    public function __construct(callable $httpClientFactory, $host, $username, $password, $tenant = null, ?ArrayAccess $tokenStore = null)
    {
        $this->httpClientFactory = $httpClientFactory;
        $this->baseUrl = self::normalizeHost($host);
        $this->username = (string) $username;
        $this->password = (string) $password;
        $this->tenant = (string) $tenant;
        $this->tokenStore = $tokenStore ?: new \ArrayObject;
    }

    /**
     * Turn "us.preservica.com", "https://us.preservica.com/" etc. into a base
     * URL without trailing slash. Returns an empty string if invalid.
     */
    public static function normalizeHost($host)
    {
        $host = trim((string) $host);
        if ($host === '') {
            return '';
        }
        if (!preg_match('#^https?://#i', $host)) {
            $host = 'https://' . $host;
        }
        $parts = parse_url($host);
        if (!$parts || empty($parts['host'])) {
            return '';
        }
        $base = strtolower($parts['scheme']) . '://' . $parts['host'];
        if (!empty($parts['port'])) {
            $base .= ':' . $parts['port'];
        }
        return $base;
    }

    public function isConfigured()
    {
        return $this->baseUrl !== '' && $this->username !== '' && $this->password !== '';
    }

    public function getBaseUrl()
    {
        return $this->baseUrl;
    }

    /**
     * Log in and return an access token, reusing a cached one when possible.
     *
     * @param bool $force Ignore the cached token
     * @return string
     */
    public function getToken($force = false)
    {
        if (!$this->isConfigured()) {
            throw new Exception('The Preservica connection is not configured. Set the host, username and password in the module configuration.'); // @translate
        }
        // The cache key ties a cached token to the credentials it was issued for.
        $key = hash('sha256', implode('|', [$this->baseUrl, $this->username, $this->password, $this->tenant]));
        if (!$force
            && isset($this->tokenStore['token'], $this->tokenStore['time'], $this->tokenStore['key'])
            && $this->tokenStore['key'] === $key
            && (time() - $this->tokenStore['time']) < self::TOKEN_LIFETIME
        ) {
            return $this->tokenStore['token'];
        }

        $post = ['username' => $this->username, 'password' => $this->password];
        if ($this->tenant !== '') {
            $post['tenant'] = $this->tenant;
        }
        $client = $this->newHttpClient();
        $client->setUri($this->baseUrl . '/api/accesstoken/login');
        $client->setMethod('POST');
        $client->setParameterPost($post);
        try {
            $response = $client->send();
        } catch (\Exception $e) {
            throw new Exception(sprintf('Could not reach Preservica at %s: %s', $this->baseUrl, $e->getMessage()), 0, $e);
        }
        $data = json_decode($response->getBody(), true);
        if (!$response->isSuccess() || !is_array($data) || empty($data['token'])) {
            if (is_array($data) && !empty($data['continuationToken'])) {
                throw new Exception('Preservica asked for a second authentication factor. Use a service account without two-factor authentication.'); // @translate
            }
            $reason = is_array($data) && !empty($data['message']) ? $data['message'] : $response->getReasonPhrase();
            throw new Exception(sprintf('Preservica login failed (HTTP %s): %s', $response->getStatusCode(), $reason), $response->getStatusCode());
        }
        $this->tokenStore['token'] = $data['token'];
        $this->tokenStore['time'] = time();
        $this->tokenStore['key'] = $key;
        return $data['token'];
    }

    /**
     * Search for assets with the Content API.
     *
     * @param string $query Search terms; "%" matches everything
     * @param int $start Zero-based offset
     * @param int $max Page size
     * @return array ['total' => int, 'results' => [['ref', 'title', 'description'], ...]]
     */
    public function searchAssets($query, $start = 0, $max = 25)
    {
        $query = trim((string) $query);
        $q = [
            'q' => $query === '' ? '%' : $query,
            'fields' => [
                ['name' => 'xip.document_type', 'values' => ['IO']],
            ],
        ];
        $response = $this->request('/api/content/search', [
            'method' => 'POST',
            'post' => [
                'q' => json_encode($q),
                'start' => (int) $start,
                'max' => (int) $max,
                'metadata' => 'xip.title,xip.description,xip.document_type',
            ],
        ]);
        $data = json_decode($response->getBody(), true);
        if (!is_array($data) || !isset($data['value'])) {
            throw new Exception('Unexpected response from the Preservica search API.'); // @translate
        }
        $value = $data['value'];
        $objectIds = isset($value['objectIds']) ? $value['objectIds'] : [];
        $metadata = isset($value['metadata']) ? $value['metadata'] : [];
        $results = [];
        foreach ($objectIds as $i => $objectId) {
            // Object IDs look like "sdb:IO|<uuid>".
            $parts = explode('|', $objectId);
            $ref = end($parts);
            if (strpos($objectId, 'IO|') === false || !preg_match(self::REF_PATTERN, $ref)) {
                continue;
            }
            $row = ['ref' => strtolower($ref), 'title' => '', 'description' => ''];
            foreach (isset($metadata[$i]) ? $metadata[$i] : [] as $field) {
                if (!isset($field['name'])) {
                    continue;
                }
                $fieldValue = isset($field['value']) ? $field['value'] : '';
                if (is_array($fieldValue)) {
                    $fieldValue = implode('; ', $fieldValue);
                }
                if ($field['name'] === 'xip.title') {
                    $row['title'] = (string) $fieldValue;
                } elseif ($field['name'] === 'xip.description') {
                    $row['description'] = (string) $fieldValue;
                }
            }
            $results[] = $row;
        }
        return [
            'total' => isset($value['totalHits']) ? (int) $value['totalHits'] : count($results),
            'results' => $results,
        ];
    }

    /**
     * Fetch a folder's title, description and parent.
     *
     * @return array ['ref', 'title', 'description', 'parent' => string|null]
     */
    public function getFolder($ref)
    {
        $this->assertRef($ref);
        $xpath = $this->requestXml('/api/entity/structural-objects/' . $ref);
        $base = '//*[local-name()="StructuralObject"]/*[local-name()="%s"]';
        $parent = strtolower($this->text($xpath, sprintf($base, 'Parent')));
        return [
            'ref' => strtolower($ref),
            'title' => $this->text($xpath, sprintf($base, 'Title')),
            'description' => $this->text($xpath, sprintf($base, 'Description')),
            'parent' => preg_match(self::REF_PATTERN, $parent) ? $parent : null,
        ];
    }

    /**
     * The folders from the top of the archive down to a folder, inclusive.
     *
     * @return array [['ref', 'title', 'description', 'parent'], ...]
     */
    public function getFolderPath($ref)
    {
        $path = [];
        $seen = [];
        // Preservica trees are rarely deep; the limit guards against loops.
        while ($ref && !isset($seen[$ref]) && count($path) < 50) {
            $seen[$ref] = true;
            $folder = $this->getFolder($ref);
            array_unshift($path, $folder);
            $ref = $folder['parent'];
        }
        return $path;
    }

    /**
     * List one page of a folder's children, or of the top-level folders.
     *
     * @param string|null $ref Folder reference; null for the top level
     * @param int $start Zero-based offset
     * @param int $max Page size (Preservica allows up to 1000)
     * @return array ['total' => int, 'folders' => [['ref', 'title']], 'assets' => [['ref', 'title']]]
     */
    public function listChildren($ref = null, $start = 0, $max = 100)
    {
        if ($ref === null) {
            $path = '/api/entity/root/children';
        } else {
            $this->assertRef($ref);
            $path = '/api/entity/structural-objects/' . $ref . '/children';
        }
        $xpath = $this->requestXml($path, ['start' => (int) $start, 'max' => (int) $max]);
        $page = ['total' => 0, 'folders' => [], 'assets' => []];
        foreach ($xpath->query('//*[local-name()="Children"]/*[local-name()="Child"]') as $node) {
            $childRef = strtolower($node->getAttribute('ref'));
            if (!preg_match(self::REF_PATTERN, $childRef)) {
                continue;
            }
            $child = ['ref' => $childRef, 'title' => $node->getAttribute('title')];
            switch ($node->getAttribute('type')) {
                case 'SO':
                    $page['folders'][] = $child;
                    break;
                case 'IO':
                    $page['assets'][] = $child;
                    break;
            }
        }
        $total = $this->text($xpath, '//*[local-name()="Paging"]/*[local-name()="TotalResults"]');
        $page['total'] = $total === '' ? count($page['folders']) + count($page['assets']) : (int) $total;
        return $page;
    }

    /**
     * Walk the assets in a folder, page by page, optionally descending into
     * its subfolders (breadth first). Each asset is yielded once.
     *
     * @param string $ref Folder reference
     * @param bool $recursive Include assets in subfolders
     * @param int $pageSize
     * @return \Generator Yields asset references
     */
    public function walkFolderAssets($ref, $recursive = true, $pageSize = 100)
    {
        $this->assertRef($ref);
        $queue = [strtolower($ref)];
        $seenFolders = [strtolower($ref) => true];
        $seenAssets = [];
        while ($queue) {
            $folderRef = array_shift($queue);
            $start = 0;
            do {
                $page = $this->listChildren($folderRef, $start, $pageSize);
                foreach ($page['assets'] as $asset) {
                    if (!isset($seenAssets[$asset['ref']])) {
                        $seenAssets[$asset['ref']] = true;
                        yield $asset['ref'];
                    }
                }
                if ($recursive) {
                    foreach ($page['folders'] as $folder) {
                        if (!isset($seenFolders[$folder['ref']])) {
                            $seenFolders[$folder['ref']] = true;
                            $queue[] = $folder['ref'];
                        }
                    }
                }
                $count = count($page['assets']) + count($page['folders']);
                $start += $pageSize;
            } while ($count > 0 && $start < $page['total']);
        }
    }

    /**
     * Fetch an asset thumbnail from the Content API.
     *
     * @return array|null ['body' => string, 'type' => string] or null if none
     */
    public function getThumbnail($ref, $size = 'medium')
    {
        $this->assertRef($ref);
        try {
            $response = $this->request('/api/content/thumbnail', [
                'query' => ['id' => 'sdb:IO|' . $ref, 'size' => $size],
            ]);
        } catch (Exception $e) {
            return null;
        }
        $type = $response->getHeaders()->get('Content-Type');
        $type = $type ? $type->getFieldValue() : '';
        if (stripos($type, 'image/') !== 0) {
            return null;
        }
        return ['body' => $response->getBody(), 'type' => $type];
    }

    /**
     * Fetch an asset's core properties and descriptive metadata.
     *
     * Dublin Core and MODS metadata are both returned under 'dc', keyed by
     * Dublin Core Terms local name.
     *
     * @return array ['ref', 'title', 'description', 'securityTag', 'dc' => [term => [values]]]
     */
    public function getAsset($ref)
    {
        $this->assertRef($ref);
        $xpath = $this->requestXml('/api/entity/information-objects/' . $ref);
        $asset = [
            'ref' => strtolower($ref),
            'title' => $this->text($xpath, '//*[local-name()="InformationObject"]/*[local-name()="Title"]'),
            'description' => $this->text($xpath, '//*[local-name()="InformationObject"]/*[local-name()="Description"]'),
            'securityTag' => $this->text($xpath, '//*[local-name()="InformationObject"]/*[local-name()="SecurityTag"]'),
            'dc' => [],
        ];
        foreach ($xpath->query('//*[local-name()="Metadata"]/*[local-name()="Fragment"]') as $fragment) {
            $path = $this->toPath(trim($fragment->textContent));
            if (!$path) {
                continue;
            }
            $fragmentXpath = $this->requestXml($path);
            foreach ($fragmentXpath->query('//*[local-name()="Content"]//*') as $element) {
                $namespace = $element->namespaceURI;
                if ($namespace !== 'http://purl.org/dc/elements/1.1/' && $namespace !== 'http://purl.org/dc/terms/') {
                    continue;
                }
                if ($this->hasChildElements($element)) {
                    continue;
                }
                $value = trim($element->textContent);
                if ($value === '') {
                    continue;
                }
                $this->addValue($asset['dc'], $element->localName, $value);
            }
            // MODS records are mapped to Dublin Core Terms.
            foreach ($fragmentXpath->query('//*[local-name()="Content"]//*[local-name()="mods"]') as $mods) {
                if ($mods->namespaceURI !== ModsMapper::MODS_NS) {
                    continue;
                }
                foreach ((new ModsMapper)->map($mods) as $name => $values) {
                    foreach ($values as $value) {
                        $this->addValue($asset['dc'], $name, $value);
                    }
                }
            }
        }
        return $asset;
    }

    /**
     * Add a value unless one with the same text is already there. A value
     * is a string, or ['uri' => ..., 'label' => ...] for a linked value,
     * which replaces a plain value with the same text.
     */
    protected function addValue(array &$dc, $name, $value)
    {
        $label = is_array($value) ? $value['label'] : $value;
        foreach (isset($dc[$name]) ? $dc[$name] : [] as $i => $existing) {
            if ((is_array($existing) ? $existing['label'] : $existing) === $label) {
                if (is_array($value) && !is_array($existing)) {
                    $dc[$name][$i] = $value;
                }
                return;
            }
        }
        $dc[$name][] = $value;
    }

    /**
     * List the downloadable files of an asset: one per content object, taken
     * from its active generation, from the Access representation when there
     * is one.
     *
     * @param string $ref
     * @param bool $fallbackToPreservation Use the Preservation representation
     *   when the asset has no Access representation
     * @return array [['filename', 'size', 'contentPath', 'representation'], ...]
     */
    public function getFiles($ref, $fallbackToPreservation = true)
    {
        $this->assertRef($ref);
        $xpath = $this->requestXml('/api/entity/information-objects/' . $ref . '/representations');
        $representations = ['Access' => [], 'Preservation' => []];
        foreach ($xpath->query('//*[local-name()="Representations"]/*[local-name()="Representation"]') as $node) {
            $type = $node->getAttribute('type');
            $path = $this->toPath(trim($node->textContent));
            if ($path && isset($representations[$type])) {
                $representations[$type][] = $path;
            }
        }
        $type = 'Access';
        if (!$representations['Access']) {
            if (!$fallbackToPreservation) {
                return [];
            }
            $type = 'Preservation';
        }
        if (!$representations[$type]) {
            return [];
        }

        // Use the first representation of the chosen type.
        $xpath = $this->requestXml($representations[$type][0]);
        $files = [];
        $seen = [];
        foreach ($xpath->query('//*[local-name()="ContentObjects"]/*[local-name()="ContentObject"]') as $node) {
            $contentObjectRef = $node->getAttribute('ref');
            if (!$contentObjectRef) {
                $contentObjectRef = basename((string) parse_url(trim($node->textContent), PHP_URL_PATH));
            }
            // The same content object can be listed more than once in the response.
            if (!preg_match(self::REF_PATTERN, $contentObjectRef) || isset($seen[$contentObjectRef])) {
                continue;
            }
            $seen[$contentObjectRef] = true;
            foreach ($this->getContentObjectFiles($contentObjectRef) as $file) {
                $file['representation'] = $type;
                $files[] = $file;
            }
        }
        return $files;
    }

    /**
     * Get the bitstreams of a content object's active generation.
     */
    protected function getContentObjectFiles($contentObjectRef)
    {
        $xpath = $this->requestXml('/api/entity/content-objects/' . $contentObjectRef . '/generations');
        $generationPaths = [];
        foreach ($xpath->query('//*[local-name()="Generations"]/*[local-name()="Generation"]') as $node) {
            $path = $this->toPath(trim($node->textContent));
            if ($path) {
                $generationPaths[] = $path;
            }
        }
        // Use the newest generation that is flagged active (normally the
        // last one); fall back to the newest if none says so.
        $xpath = null;
        $newest = null;
        foreach (array_reverse($generationPaths) as $path) {
            $candidate = $this->requestXml($path);
            if ($newest === null) {
                $newest = $candidate;
            }
            if ($this->text($candidate, '//*[local-name()="Generation"]/@active') === 'true') {
                $xpath = $candidate;
                break;
            }
        }
        $xpath = $xpath ?: $newest;
        if (!$xpath) {
            return [];
        }
        $files = [];
        foreach ($xpath->query('//*[local-name()="Bitstreams"]/*[local-name()="Bitstream"]') as $node) {
            $bitstreamPath = $this->toPath(trim($node->textContent));
            if (!$bitstreamPath) {
                continue;
            }
            $contentPath = rtrim($bitstreamPath, '/') . '/content';
            if (!preg_match(self::CONTENT_PATH_PATTERN, $contentPath)) {
                continue;
            }
            $filename = $node->getAttribute('filename');
            $size = null;
            if ($filename === '') {
                $bitstreamXpath = $this->requestXml($bitstreamPath);
                $filename = $this->text($bitstreamXpath, '//*[local-name()="Bitstream"]/*[local-name()="Filename"]');
                $size = $this->text($bitstreamXpath, '//*[local-name()="Bitstream"]/*[local-name()="FileSize"]');
            }
            $files[$contentPath] = [
                'filename' => $filename !== '' ? $filename : basename($bitstreamPath),
                'size' => $size === null || $size === '' ? null : (int) $size,
                'contentPath' => $contentPath,
            ];
        }
        return array_values($files);
    }

    /**
     * Stream a bitstream's content to a local file.
     *
     * @param string $contentPath A path returned by getFiles()
     * @param string $destination Local file path to write to
     */
    public function download($contentPath, $destination)
    {
        if (!preg_match(self::CONTENT_PATH_PATTERN, (string) $contentPath)) {
            throw new Exception('Invalid Preservica content path.'); // @translate
        }
        $this->request($contentPath, ['stream' => $destination, 'timeout' => 3600]);
    }

    /**
     * Send an authenticated request, logging in again once if the token was
     * rejected.
     *
     * @return Response
     */
    protected function request($path, array $options = [])
    {
        $response = null;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $client = $this->newHttpClient();
            $client->setUri($this->baseUrl . $path);
            $client->setMethod(isset($options['method']) ? $options['method'] : 'GET');
            $client->setHeaders(['Preservica-Access-Token' => $this->getToken($attempt > 0)]);
            if (isset($options['query'])) {
                $client->setParameterGet($options['query']);
            }
            if (isset($options['post'])) {
                $client->setParameterPost($options['post']);
            }
            if (isset($options['timeout'])) {
                $client->setOptions(['timeout' => $options['timeout']]);
            }
            if (isset($options['stream'])) {
                // Compressed responses are broken alongside streaming.
                $client->getRequest()->getHeaders()->addHeaderLine('Accept-Encoding', 'identity');
                $client->setStream($options['stream']);
            }
            try {
                $response = $client->send();
            } catch (\Exception $e) {
                throw new Exception(sprintf('Request to Preservica failed (%s): %s', $path, $e->getMessage()), 0, $e);
            }
            if ($response->getStatusCode() !== 401) {
                break;
            }
        }
        if (!$response->isSuccess()) {
            throw new Exception(
                sprintf('Preservica returned HTTP %s %s for %s', $response->getStatusCode(), $response->getReasonPhrase(), $path),
                $response->getStatusCode()
            );
        }
        return $response;
    }

    /**
     * @return DOMXPath
     */
    protected function requestXml($path, array $query = [])
    {
        $response = $this->request($path, $query ? ['query' => $query] : []);
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($response->getBody(), LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            throw new Exception(sprintf('Preservica returned invalid XML for %s', $path));
        }
        return new DOMXPath($dom);
    }

    protected function text(DOMXPath $xpath, $expression)
    {
        $nodes = $xpath->query($expression);
        return $nodes && $nodes->length ? trim($nodes->item(0)->textContent) : '';
    }

    protected function hasChildElements(DOMElement $element)
    {
        foreach ($element->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE) {
                return true;
            }
        }
        return false;
    }

    /**
     * Reduce a URL supplied by Preservica to its path, so that requests only
     * ever go to the configured host. Returns null for anything that is not
     * an Entity API URL.
     */
    protected function toPath($url)
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!$path || strpos($path, '/api/entity/') !== 0 || strpos($path, '..') !== false) {
            return null;
        }
        return $path;
    }

    protected function assertRef($ref)
    {
        if (!preg_match(self::REF_PATTERN, (string) $ref)) {
            throw new Exception(sprintf('"%s" is not a valid Preservica reference.', $ref));
        }
    }

    /**
     * @return HttpClient
     */
    protected function newHttpClient()
    {
        $client = call_user_func($this->httpClientFactory);
        $client->setOptions(['timeout' => 60]);
        return $client;
    }
}
