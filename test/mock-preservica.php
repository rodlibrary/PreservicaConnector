<?php
// Stub of the Preservica Access Token, Content and Entity APIs for testing.
// Response shapes follow the public Preservica API documentation and the
// pyPreservica SDK. Tokens expire after 8 uses to exercise re-login.

$stateFile = sys_get_temp_dir() . '/mock-preservica-state.json';
$state = is_file($stateFile) ? json_decode(file_get_contents($stateFile), true) : ['tokens' => [], 'logins' => 0];
$save = function () use (&$state, $stateFile) { file_put_contents($stateFile, json_encode($state)); };

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
error_log("$method {$_SERVER['REQUEST_URI']}");

const NS_ENTITY = 'http://preservica.com/EntityAPI/v7.7';
const NS_XIP = 'http://preservica.com/XIP/v7.7';
const BASE = 'https://mock.preservica.example'; // deliberately not this server

$assets = [
    'a1111111-1111-4111-8111-111111111111' => [
        'title' => 'Campus aerial photograph, 1954',
        'description' => 'Aerial view of the main quad looking north.',
        'dc' => ['title' => ['Campus aerial photograph, 1954'], 'creator' => ['University Photo Service'], 'date' => ['1954-06-01'], 'subject' => ['Aerial photographs', 'Campus planning'], 'rights' => ['http://rightsstatements.org/vocab/InC/1.0/']],
        'mods' => '<mods:mods xmlns:mods="http://www.loc.gov/mods/v3"><mods:titleInfo><mods:title>Campus aerial photograph, 1954</mods:title></mods:titleInfo><mods:subject><mods:topic valueURI="http://id.loc.gov/authorities/subjects/sh85001255">Aerial photographs</mods:topic></mods:subject><mods:subject><mods:geographic>Cedar Falls (Iowa)</mods:geographic></mods:subject><mods:physicalDescription><mods:extent>1 photograph</mods:extent></mods:physicalDescription></mods:mods>',
        'reps' => ['Preservation' => ['c1111111-1111-4111-8111-11111111aaaa'], 'Access' => ['c1111111-1111-4111-8111-11111111bbbb', 'c1111111-1111-4111-8111-11111111cccc']],
    ],
    'a2222222-2222-4222-8222-222222222222' => [
        'title' => 'Commencement program',
        'description' => 'Printed program. Preservation copy only.',
        'dc' => [],
        'mods' => '<mods xmlns="http://www.loc.gov/mods/v3"><titleInfo><title>Commencement program</title></titleInfo><name><namePart>State Teachers College</namePart></name><originInfo><dateCreated>1931-06-02</dateCreated></originInfo><typeOfResource>text</typeOfResource></mods>',
        'reps' => ['Preservation' => ['c2222222-2222-4222-8222-22222222aaaa']],
    ],
    'a3333333-3333-4333-8333-333333333333' => [
        'title' => 'Campus oral history (restricted download)',
        'description' => 'File download fails on purpose.',
        'dc' => ['title' => ['Campus oral history']],
        'reps' => ['Access' => ['c3333333-3333-4333-8333-33333333dead']],
    ],
];
$files = [
    'c1111111-1111-4111-8111-11111111aaaa' => 'aerial-master.tif',
    'c1111111-1111-4111-8111-11111111bbbb' => 'aerial-front.jpg',
    'c1111111-1111-4111-8111-11111111cccc' => 'aerial-back.jpg',
    'c2222222-2222-4222-8222-22222222aaaa' => 'program.png',
    'c3333333-3333-4333-8333-33333333dead' => 'interview.jpg',
];

$json = function ($data, $code = 200) { http_response_code($code); header('Content-Type: application/json'); echo json_encode($data); };
$xml = function ($body) { header('Content-Type: application/xml'); echo '<?xml version="1.0" encoding="UTF-8"?>' . $body; };
$image = function ($label, $type, $w = 640, $h = 480) {
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 40, 90, 140));
    imagestring($im, 5, 20, 20, $label, imagecolorallocate($im, 255, 255, 255));
    if ($type === 'png') { header('Content-Type: image/png'); imagepng($im); } else { header('Content-Type: image/jpeg'); imagejpeg($im); }
};

if ($path === '/api/accesstoken/login' && $method === 'POST') {
    if (($_POST['username'] ?? '') !== 'svc-omeka@uni.example' || ($_POST['password'] ?? '') !== 's3cret') {
        return $json(['success' => false, 'message' => 'Invalid username or password'], 401);
    }
    $token = bin2hex(random_bytes(8));
    $state['tokens'][$token] = 0;
    $state['logins']++;
    $save();
    return $json(['success' => true, 'token' => $token, 'refresh-token' => 'r', 'validFor' => 15, 'user' => $_POST['username']]);
}
if ($path === '/__state') {
    return $json(['logins' => $state['logins']]);
}

$token = $_SERVER['HTTP_PRESERVICA_ACCESS_TOKEN'] ?? '';
if (!isset($state['tokens'][$token]) || $state['tokens'][$token] >= 8) {
    return $json(['message' => 'Unauthorized'], 401);
}
$state['tokens'][$token]++;
$save();

if ($path === '/api/content/search' && $method === 'POST') {
    $q = json_decode($_POST['q'] ?? '', true);
    $isIoOnly = ($q['fields'][0]['name'] ?? '') === 'xip.document_type' && ($q['fields'][0]['values'] ?? []) === ['IO'];
    if (!$isIoOnly) {
        return $json(['success' => false], 400);
    }
    $term = strtolower($q['q'] ?? '');
    $hits = [];
    foreach ($assets as $ref => $asset) {
        if ($term === '%' || strpos(strtolower($asset['title'] . ' ' . $asset['description']), $term) !== false) {
            $hits[$ref] = $asset;
        }
    }
    $page = array_slice($hits, (int) ($_POST['start'] ?? 0), (int) ($_POST['max'] ?? 10), true);
    $objectIds = [];
    $metadata = [];
    foreach ($page as $ref => $asset) {
        $objectIds[] = 'sdb:IO|' . $ref;
        $metadata[] = [
            ['name' => 'xip.title', 'value' => $asset['title']],
            ['name' => 'xip.description', 'value' => $asset['description']],
            ['name' => 'xip.document_type', 'value' => 'IO'],
        ];
    }
    return $json(['success' => true, 'version' => 1, 'value' => ['objectIds' => $objectIds, 'totalHits' => count($hits), 'metadata' => $metadata]]);
}
if ($path === '/api/content/thumbnail') {
    $ref = substr($_GET['id'] ?? '', strlen('sdb:IO|'));
    if (!isset($assets[$ref])) { http_response_code(404); return; }
    return $image(substr($assets[$ref]['title'], 0, 12), 'png', 128, 128);
}

$uuid = '([0-9a-f-]{36})';
if (preg_match("#^/api/entity/information-objects/$uuid$#", $path, $m) && isset($assets[$m[1]])) {
    $a = $assets[$m[1]];
    $modsFragment = isset($a['mods']) ? '<Fragment schema="http://www.loc.gov/mods/v3">' . BASE . "/api/entity/information-objects/{$m[1]}/metadata/f0000000-0000-4000-8000-000000000002</Fragment>" : '';
    $fragments = $modsFragment . ($a['dc'] ? '<Fragment schema="http://www.openarchives.org/OAI/2.0/oai_dc/">' . BASE . "/api/entity/information-objects/{$m[1]}/metadata/f0000000-0000-4000-8000-000000000001</Fragment>" : '');
    return $xml('<EntityResponse xmlns="' . NS_ENTITY . '" xmlns:xip="' . NS_XIP . '"><xip:InformationObject><xip:Ref>' . $m[1] . '</xip:Ref><xip:Title>' . htmlspecialchars($a['title']) . '</xip:Title><xip:Description>' . htmlspecialchars($a['description']) . '</xip:Description><xip:SecurityTag>open</xip:SecurityTag><xip:Parent>d0000000-0000-4000-8000-000000000000</xip:Parent></xip:InformationObject><AdditionalInformation><Self>' . BASE . $path . '</Self><Metadata>' . $fragments . '</Metadata></AdditionalInformation></EntityResponse>');
}
if (preg_match("#^/api/entity/information-objects/$uuid/metadata/$uuid$#", $path, $m) && isset($assets[$m[1]])) {
    if (substr($m[2], -1) === '2') {
        return $xml('<MetadataResponse xmlns="' . NS_ENTITY . '" xmlns:xip="' . NS_XIP . '"><xip:MetadataContainer schemaUri="http://www.loc.gov/mods/v3"><xip:Ref>' . $m[2] . '</xip:Ref><xip:Entity>' . $m[1] . '</xip:Entity><xip:Content>' . $assets[$m[1]]['mods'] . '</xip:Content></xip:MetadataContainer></MetadataResponse>');
    }
    $dc = '';
    foreach ($assets[$m[1]]['dc'] as $name => $values) {
        foreach ($values as $value) {
            $dc .= "<dc:$name>" . htmlspecialchars($value) . "</dc:$name>";
        }
    }
    return $xml('<MetadataResponse xmlns="' . NS_ENTITY . '" xmlns:xip="' . NS_XIP . '"><xip:MetadataContainer schemaUri="http://www.openarchives.org/OAI/2.0/oai_dc/"><xip:Ref>' . $m[2] . '</xip:Ref><xip:Entity>' . $m[1] . '</xip:Entity><xip:Content><oai_dc:dc xmlns:oai_dc="http://www.openarchives.org/OAI/2.0/oai_dc/" xmlns:dc="http://purl.org/dc/elements/1.1/">' . $dc . '</oai_dc:dc></xip:Content></xip:MetadataContainer></MetadataResponse>');
}
if (preg_match("#^/api/entity/information-objects/$uuid/representations$#", $path, $m) && isset($assets[$m[1]])) {
    $reps = '';
    foreach ($assets[$m[1]]['reps'] as $type => $cos) {
        $reps .= '<Representation type="' . $type . '" name="' . $type . '">' . BASE . $path . '/' . $type . '</Representation>';
    }
    return $xml('<RepresentationsResponse xmlns="' . NS_ENTITY . '" xmlns:xip="' . NS_XIP . '"><Representations>' . $reps . '</Representations></RepresentationsResponse>');
}
if (preg_match("#^/api/entity/information-objects/$uuid/representations/(Access|Preservation)$#", $path, $m) && isset($assets[$m[1]]['reps'][$m[2]])) {
    $cos = '';
    foreach ($assets[$m[1]]['reps'][$m[2]] as $co) {
        $cos .= '<xip:ContentObject>' . $co . '</xip:ContentObject>';
    }
    $links = '';
    foreach ($assets[$m[1]]['reps'][$m[2]] as $co) {
        $links .= '<ContentObject title="' . $files[$co] . '" ref="' . $co . '">' . BASE . '/api/entity/content-objects/' . $co . '</ContentObject>';
    }
    return $xml('<RepresentationResponse xmlns="' . NS_ENTITY . '" xmlns:xip="' . NS_XIP . '"><xip:Representation><xip:InformationObject>' . $m[1] . '</xip:InformationObject><xip:Name>' . $m[2] . '</xip:Name><xip:Type>' . $m[2] . '</xip:Type><xip:ContentObjects>' . $cos . '</xip:ContentObjects></xip:Representation><ContentObjects>' . $links . '</ContentObjects></RepresentationResponse>');
}
if (preg_match("#^/api/entity/content-objects/$uuid/generations$#", $path, $m) && isset($files[$m[1]])) {
    return $xml('<GenerationsResponse xmlns="' . NS_ENTITY . '" xmlns:xip="' . NS_XIP . '"><Generations><Generation>' . BASE . $path . '/1</Generation><Generation>' . BASE . $path . '/2</Generation></Generations></GenerationsResponse>');
}
if (preg_match("#^/api/entity/content-objects/$uuid/generations/(\d+)$#", $path, $m) && isset($files[$m[1]])) {
    return $xml('<GenerationResponse xmlns="' . NS_ENTITY . '" xmlns:xip="' . NS_XIP . '"><xip:Generation original="true" active="' . ($m[2] === '2' ? 'true' : 'false') . '"><xip:ContentObject>' . $m[1] . '</xip:ContentObject><xip:Bitstreams><xip:Bitstream>' . $files[$m[1]] . '</xip:Bitstream></xip:Bitstreams></xip:Generation><Bitstreams><Bitstream>' . BASE . $path . '/bitstreams/1</Bitstream></Bitstreams></GenerationResponse>');
}
if (preg_match("#^/api/entity/content-objects/$uuid/generations/(\d+)/bitstreams/1$#", $path, $m) && isset($files[$m[1]])) {
    return $xml('<BitstreamResponse xmlns="' . NS_ENTITY . '" xmlns:xip="' . NS_XIP . '"><xip:Bitstream><xip:Filename>' . $files[$m[1]] . '</xip:Filename><xip:FileSize>1234</xip:FileSize></xip:Bitstream><AdditionalInformation><Content>' . BASE . $path . '/content</Content></AdditionalInformation></BitstreamResponse>');
}
if (preg_match("#^/api/entity/content-objects/$uuid/generations/(\d+)/bitstreams/1/content$#", $path, $m) && isset($files[$m[1]])) {
    if ($m[2] !== '2' || substr($m[1], -4) === 'dead') { http_response_code(403); return; }
    $name = $files[$m[1]];
    header('Content-Disposition: attachment; filename="' . $name . '"');
    return $image($name, pathinfo($name, PATHINFO_EXTENSION) === 'png' ? 'png' : 'jpg');
}
http_response_code(404);
echo 'not found: ' . $path;
