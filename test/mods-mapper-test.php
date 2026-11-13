<?php
// Unit test for the MODS to Dublin Core Terms crosswalk. Run: php test/mods-mapper-test.php
require __DIR__ . '/../src/Metadata/ModsMapper.php';

$xml = <<<XML
<mods xmlns="http://www.loc.gov/mods/v3" xmlns:xlink="http://www.w3.org/1999/xlink" version="3.7">
  <titleInfo><nonSort>The</nonSort><title>Old Gold</title><subTitle>student yearbook</subTitle><partNumber>Vol. 12</partNumber></titleInfo>
  <titleInfo type="alternative"><title>Old Gold annual</title></titleInfo>
  <name type="personal" valueURI="http://id.loc.gov/authorities/names/n00000001">
    <namePart type="given">Ada</namePart><namePart type="family">Smith</namePart><namePart type="date">1890-1970</namePart>
    <role><roleTerm type="text">Photographer</roleTerm></role>
  </name>
  <name type="corporate" usage="primary"><namePart>State Teachers College</namePart><role><roleTerm type="code">pbl</roleTerm></role></name>
  <name type="personal"><namePart>Jones, Bea</namePart><role><roleTerm type="code" authority="marcrelator">aut</roleTerm></role></name>
  <name type="personal"><displayForm>Anonymous donor</displayForm><role><roleTerm>donor</roleTerm></role></name>
  <typeOfResource>still image</typeOfResource>
  <genre authority="aat" valueURI="http://vocab.getty.edu/aat/300028051">yearbooks</genre>
  <originInfo>
    <place><placeTerm type="text">Cedar Falls, Iowa</placeTerm></place>
    <publisher>State Teachers College</publisher>
    <dateCreated encoding="w3cdtf" point="start">1920</dateCreated>
    <dateCreated encoding="w3cdtf" point="end">1921</dateCreated>
    <dateIssued>1921</dateIssued>
    <copyrightDate>1921</copyrightDate>
  </originInfo>
  <language><languageTerm type="code" authority="iso639-2b">eng</languageTerm></language>
  <physicalDescription><extent>212 pages</extent><form>print</form><internetMediaType>image/tiff</internetMediaType><digitalOrigin>reformatted digital</digitalOrigin></physicalDescription>
  <abstract>Annual   yearbook
     of the college.</abstract>
  <note type="provenance">Gift of the alumni association.</note>
  <tableOfContents>Faculty -- Classes -- Athletics</tableOfContents>
  <targetAudience>general</targetAudience>
  <subject authority="lcsh" valueURI="http://id.loc.gov/authorities/subjects/sh85000001"><topic>College yearbooks</topic></subject>
  <subject authority="lcsh"><topic>Universities and colleges</topic><geographic>Iowa</geographic><temporal>20th century</temporal><genre>Periodicals</genre></subject>
  <subject><geographic valueURI="http://sws.geonames.org/4850699/">Cedar Falls (Iowa)</geographic></subject>
  <subject><hierarchicalGeographic><country>United States</country><state>Iowa</state><city>Cedar Falls</city></hierarchicalGeographic></subject>
  <subject><temporal>1920-1929</temporal></subject>
  <subject><name type="personal"><namePart>Seerley, Homer H.</namePart></name></subject>
  <subject><topic>College yearbooks</topic></subject>
  <subject><topic></topic></subject>
  <classification authority="lcc">LD2585</classification>
  <relatedItem type="host"><titleInfo><title>University Archives yearbook collection</title></titleInfo></relatedItem>
  <relatedItem type="original"><location><url>https://example.org/original/12</url></location></relatedItem>
  <relatedItem type="otherVersion"><identifier>oldgold-1921-pdf</identifier></relatedItem>
  <identifier type="local">ua-yb-1921</identifier>
  <identifier type="isbn" invalid="yes">000</identifier>
  <location><physicalLocation>Special Collections</physicalLocation><url>https://example.org/yb/1921</url></location>
  <accessCondition type="use and reproduction" xlink:href="http://rightsstatements.org/vocab/NoC-US/1.0/">No Copyright - United States</accessCondition>
  <accessCondition type="restriction on access">Open for research.</accessCondition>
  <recordInfo><recordOrigin>Converted from MARC</recordOrigin></recordInfo>
  <dc:title xmlns:dc="http://purl.org/dc/elements/1.1/">ignored by the MODS mapper</dc:title>
</mods>
XML;

$expected = [
    'title' => ['The Old Gold: student yearbook. Vol. 12'],
    'alternative' => ['Old Gold annual'],
    'contributor' => [
        ['uri' => 'http://id.loc.gov/authorities/names/n00000001', 'label' => 'Smith, Ada, 1890-1970'],
        'Anonymous donor',
    ],
    'creator' => ['State Teachers College', 'Jones, Bea'],
    'type' => ['still image', ['uri' => 'http://vocab.getty.edu/aat/300028051', 'label' => 'yearbooks']],
    'publisher' => ['State Teachers College'],
    'language' => ['eng'],
    'extent' => ['212 pages'],
    'format' => ['print', 'image/tiff'],
    'description' => ['Annual yearbook of the college.', 'Gift of the alumni association.'],
    'tableOfContents' => ['Faculty -- Classes -- Athletics'],
    'audience' => ['general'],
    'subject' => [
        ['uri' => 'http://id.loc.gov/authorities/subjects/sh85000001', 'label' => 'College yearbooks'],
        'Universities and colleges--Iowa--20th century--Periodicals',
        'Seerley, Homer H.',
        'LD2585',
    ],
    'spatial' => [
        ['uri' => 'http://sws.geonames.org/4850699/', 'label' => 'Cedar Falls (Iowa)'],
        'United States--Iowa--Cedar Falls',
    ],
    'temporal' => ['1920-1929'],
    'isPartOf' => ['University Archives yearbook collection'],
    'source' => ['https://example.org/original/12'],
    'relation' => ['oldgold-1921-pdf'],
    'identifier' => ['ua-yb-1921', 'https://example.org/yb/1921'],
    'rights' => [
        ['uri' => 'http://rightsstatements.org/vocab/NoC-US/1.0/', 'label' => 'No Copyright - United States'],
        'Open for research.',
    ],
    'date' => ['1921', '1920/1921'],
    'dateCopyrighted' => ['1921'],
];

$dom = new DOMDocument;
$dom->loadXML($xml);
$actual = (new PreservicaConnector\Metadata\ModsMapper)->map($dom->documentElement);

$failed = 0;
foreach (array_unique(array_merge(array_keys($expected), array_keys($actual))) as $term) {
    $e = isset($expected[$term]) ? $expected[$term] : null;
    $a = isset($actual[$term]) ? $actual[$term] : null;
    if ($e === $a) {
        echo "PASS $term\n";
    } else {
        $failed++;
        echo "FAIL $term\n  expected: ", json_encode($e), "\n  actual:   ", json_encode($a), "\n";
    }
}
echo $failed ? "$failed FAILED\n" : "ALL PASSED\n";
exit($failed ? 1 : 0);
