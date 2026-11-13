<?php
namespace PreservicaConnector\Metadata;

use DOMElement;

/**
 * Maps a MODS (v3) record to Dublin Core Terms, following the Library of
 * Congress MODS-to-Dublin-Core crosswalk, with a few refinements where
 * Dublin Core Terms has a more specific property (alternative, extent,
 * spatial, temporal, isPartOf, tableOfContents, dateCopyrighted, audience).
 *
 * Each value is either a string or, when MODS gives an authority link
 * (valueURI or xlink:href), an array ['uri' => string, 'label' => string].
 */
class ModsMapper
{
    const MODS_NS = 'http://www.loc.gov/mods/v3';

    const XLINK_NS = 'http://www.w3.org/1999/xlink';

    /** Role terms and MARC relator codes that mean "creator". */
    const CREATOR_ROLES = ['creator', 'cre', 'author', 'aut'];

    /** @var array Dublin Core Terms local name => values */
    protected $values;

    /**
     * @param DOMElement $mods A mods:mods element
     * @return array Dublin Core Terms local name => list of values
     */
    public function map(DOMElement $mods)
    {
        $this->values = [];
        $dates = [];
        foreach ($this->children($mods) as $element) {
            switch ($element->localName) {
                case 'titleInfo':
                    $this->add($element->getAttribute('type') === '' ? 'title' : 'alternative', $this->title($element));
                    break;
                case 'name':
                    $this->add($this->isCreator($element) ? 'creator' : 'contributor', $this->linked($element, $this->name($element)));
                    break;
                case 'typeOfResource':
                case 'genre':
                    $this->add('type', $this->linked($element, $this->text($element)));
                    break;
                case 'originInfo':
                    foreach ($this->children($element) as $child) {
                        if ($child->localName === 'publisher') {
                            $this->add('publisher', $this->text($child));
                        } elseif (in_array($child->localName, ['dateIssued', 'dateCreated', 'dateCaptured', 'dateOther', 'copyrightDate'])) {
                            $dates[] = $child;
                        }
                    }
                    break;
                case 'language':
                    foreach ($this->children($element, 'languageTerm') as $child) {
                        $this->add('language', $this->linked($child, $this->text($child)));
                    }
                    break;
                case 'physicalDescription':
                    foreach ($this->children($element) as $child) {
                        if ($child->localName === 'extent') {
                            $this->add('extent', $this->text($child));
                        } elseif (in_array($child->localName, ['form', 'internetMediaType'])) {
                            $this->add('format', $this->text($child));
                        }
                    }
                    break;
                case 'abstract':
                case 'note':
                    $this->add('description', $this->text($element));
                    break;
                case 'tableOfContents':
                    $this->add('tableOfContents', $this->text($element));
                    break;
                case 'targetAudience':
                    $this->add('audience', $this->text($element));
                    break;
                case 'subject':
                    $this->subject($element);
                    break;
                case 'classification':
                    $this->add('subject', $this->text($element));
                    break;
                case 'relatedItem':
                    $this->relatedItem($element);
                    break;
                case 'identifier':
                    if ($element->getAttribute('invalid') !== 'yes') {
                        $this->add('identifier', $this->text($element));
                    }
                    break;
                case 'location':
                    foreach ($this->children($element, 'url') as $child) {
                        $this->add('identifier', $this->text($child));
                    }
                    break;
                case 'accessCondition':
                    $href = $element->getAttributeNS(self::XLINK_NS, 'href');
                    $text = $this->text($element);
                    if ($href !== '' && $text !== '') {
                        $this->add('rights', ['uri' => $href, 'label' => $text]);
                    } elseif ($href !== '') {
                        $this->add('rights', $href);
                    } else {
                        $this->add('rights', $text);
                    }
                    break;
            }
        }
        $this->dates($dates);
        return $this->values;
    }

    /**
     * Title with its non-sorting article, subtitle and part information:
     * "The title: subtitle. Part number, Part name".
     */
    protected function title(DOMElement $titleInfo)
    {
        $parts = [];
        foreach ($this->children($titleInfo) as $child) {
            $parts[$child->localName][] = $this->text($child);
        }
        $get = function ($name) use ($parts) {
            return isset($parts[$name]) ? implode(' ', array_filter($parts[$name], 'strlen')) : '';
        };
        $title = $get('title');
        if ($get('nonSort') !== '') {
            // nonSort may or may not carry its own trailing space or apostrophe.
            $nonSort = $get('nonSort');
            $title = $nonSort . (preg_match('/[\'’\-]$/u', $nonSort) ? '' : ' ') . $title;
        }
        if ($get('subTitle') !== '') {
            $title .= ': ' . $get('subTitle');
        }
        $part = implode(', ', array_filter([$get('partNumber'), $get('partName')], 'strlen'));
        if ($part !== '') {
            $title .= ($title !== '' ? '. ' : '') . $part;
        }
        return $title;
    }

    /**
     * A name as "Family, Given, dates", or its parts in document order.
     */
    protected function name(DOMElement $name)
    {
        $family = $given = $dates = $other = [];
        foreach ($this->children($name, 'namePart') as $part) {
            $text = $this->text($part);
            if ($text === '') {
                continue;
            }
            switch ($part->getAttribute('type')) {
                case 'family':
                    $family[] = $text;
                    break;
                case 'given':
                    $given[] = $text;
                    break;
                case 'date':
                    $dates[] = $text;
                    break;
                default:
                    $other[] = $text;
            }
        }
        $parts = array_merge($family, $given, $other, $dates);
        if ($parts) {
            return implode(', ', $parts);
        }
        foreach ($this->children($name, 'displayForm') as $displayForm) {
            return $this->text($displayForm);
        }
        return '';
    }

    /**
     * A name is the creator if it has no role, is flagged as primary, or
     * has a creator/author role; otherwise it is a contributor.
     */
    protected function isCreator(DOMElement $name)
    {
        if ($name->getAttribute('usage') === 'primary') {
            return true;
        }
        $roles = [];
        foreach ($this->children($name, 'role') as $role) {
            foreach ($this->children($role, 'roleTerm') as $term) {
                $roles[] = strtolower($this->text($term));
            }
        }
        return !$roles || array_intersect($roles, self::CREATOR_ROLES);
    }

    /**
     * Subjects that are only places go to "spatial", only time periods to
     * "temporal"; everything else, including compound headings (joined with
     * "--"), goes to "subject".
     */
    protected function subject(DOMElement $subject)
    {
        $parts = [];
        $kinds = [];
        foreach ($this->children($subject) as $child) {
            switch ($child->localName) {
                case 'geographic':
                case 'geographicCode':
                    $text = $this->text($child);
                    $kind = 'spatial';
                    break;
                case 'hierarchicalGeographic':
                    $text = implode('--', array_filter(array_map([$this, 'text'], $this->children($child)), 'strlen'));
                    $kind = 'spatial';
                    break;
                case 'cartographics':
                    $text = implode('; ', array_filter(array_map([$this, 'text'], $this->children($child)), 'strlen'));
                    $kind = 'spatial';
                    break;
                case 'temporal':
                    $text = $this->text($child);
                    $kind = 'temporal';
                    break;
                case 'name':
                    $text = $this->name($child);
                    $kind = 'subject';
                    break;
                case 'titleInfo':
                    $text = $this->title($child);
                    $kind = 'subject';
                    break;
                default:
                    $text = $this->text($child);
                    $kind = 'subject';
            }
            if ($text !== '') {
                $parts[] = [$child, $text];
                $kinds[$kind] = true;
            }
        }
        if (!$parts) {
            return;
        }
        $term = count($kinds) === 1 ? key($kinds) : 'subject';
        $label = implode('--', array_column($parts, 1));
        // An authority link on the heading itself, or on its only part.
        if ($subject->getAttribute('valueURI') !== '') {
            $this->add($term, $this->linked($subject, $label));
        } elseif (count($parts) === 1) {
            $this->add($term, $this->linked($parts[0][0], $label));
        } else {
            $this->add($term, $label);
        }
    }

    /**
     * Host items and series become "isPartOf", originals "source", anything
     * else "relation". The value is the related item's title, or failing
     * that its URL or identifier.
     */
    protected function relatedItem(DOMElement $relatedItem)
    {
        $value = '';
        foreach ($this->children($relatedItem, 'titleInfo') as $titleInfo) {
            $value = $this->title($titleInfo);
            break;
        }
        if ($value === '') {
            foreach ($this->children($relatedItem, 'location') as $location) {
                foreach ($this->children($location, 'url') as $url) {
                    $value = $this->text($url);
                    break 2;
                }
            }
        }
        if ($value === '') {
            foreach ($this->children($relatedItem, 'identifier') as $identifier) {
                $value = $this->text($identifier);
                break;
            }
        }
        $href = $relatedItem->getAttributeNS(self::XLINK_NS, 'href');
        if ($value === '' && $href !== '') {
            $value = $href;
        }
        switch ($relatedItem->getAttribute('type')) {
            case 'host':
            case 'series':
                $term = 'isPartOf';
                break;
            case 'original':
                $term = 'source';
                break;
            default:
                $term = 'relation';
        }
        $this->add($term, $value);
    }

    /**
     * Dates become "date" (copyright dates "dateCopyrighted"). A start and
     * end pair of the same kind becomes one "start/end" range.
     *
     * @param DOMElement[] $elements
     */
    protected function dates(array $elements)
    {
        $ranges = [];
        foreach ($elements as $element) {
            $text = $this->text($element);
            if ($text === '') {
                continue;
            }
            $term = $element->localName === 'copyrightDate' ? 'dateCopyrighted' : 'date';
            $point = $element->getAttribute('point');
            if ($point === 'start' || $point === 'end') {
                $ranges[$term . '|' . $element->localName][$point] = $text;
            } else {
                $this->add($term, $text);
            }
        }
        foreach ($ranges as $key => $range) {
            $term = explode('|', $key)[0];
            if (isset($range['start'], $range['end'])) {
                $this->add($term, $range['start'] . '/' . $range['end']);
            } else {
                $this->add($term, reset($range));
            }
        }
    }

    /**
     * Turn a label into a linked value when the element has a valueURI.
     *
     * @return string|array
     */
    protected function linked(DOMElement $element, $label)
    {
        $uri = trim($element->getAttribute('valueURI'));
        if ($label !== '' && preg_match('#^https?://\S+$#i', $uri)) {
            return ['uri' => $uri, 'label' => $label];
        }
        return $label;
    }

    /**
     * Add a value unless it is empty or its text is already there.
     */
    protected function add($term, $value)
    {
        $label = is_array($value) ? $value['label'] : $value;
        if ($label === '') {
            return;
        }
        foreach (isset($this->values[$term]) ? $this->values[$term] : [] as $existing) {
            if ((is_array($existing) ? $existing['label'] : $existing) === $label) {
                return;
            }
        }
        $this->values[$term][] = $value;
    }

    /**
     * Element text with whitespace collapsed.
     */
    protected function text(DOMElement $element)
    {
        return trim(preg_replace('/\s+/u', ' ', $element->textContent));
    }

    /**
     * Child elements in the MODS namespace, optionally of one name.
     *
     * @return DOMElement[]
     */
    protected function children(DOMElement $element, $localName = null)
    {
        $children = [];
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement
                && $child->namespaceURI === self::MODS_NS
                && ($localName === null || $child->localName === $localName)
            ) {
                $children[] = $child;
            }
        }
        return $children;
    }
}
