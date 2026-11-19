<?php
namespace PreservicaConnector\Form;

use Laminas\Form\Element;
use Laminas\Form\Form;
use Omeka\Form\Element\ItemSetSelect;

/**
 * Options for importing a whole Preservica folder.
 */
class FolderImportForm extends Form
{
    public function init()
    {
        $this->add([
            'name' => 'folder',
            'type' => Element\Hidden::class,
        ]);
        $this->add([
            'name' => 'recursive',
            'type' => Element\Checkbox::class,
            'options' => [
                'label' => 'Include subfolders', // @translate
                'info' => 'Import the assets in every folder below this one too.', // @translate
            ],
            'attributes' => [
                'id' => 'preservica-folder-recursive',
                'value' => '1',
            ],
        ]);
        $this->add([
            'name' => 'item_set',
            'type' => ItemSetSelect::class,
            'options' => [
                'label' => 'Add to item set', // @translate
                'info' => 'By default all the assets go into one item set named after this folder. It is created on the first import and reused when the folder is imported again.', // @translate
                'prepend_value_options' => [
                    'folder' => 'Item set for this folder', // @translate
                    '' => 'No item set', // @translate
                ],
            ],
            'attributes' => [
                'id' => 'preservica-folder-item-set',
                'class' => 'chosen-select',
                'value' => 'folder',
            ],
        ]);
        $this->add([
            'name' => 'comment',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Comment', // @translate
                'info' => 'Optional. A note shown with this import under "Past imports".', // @translate
            ],
            'attributes' => [
                'id' => 'preservica-folder-comment',
            ],
        ]);

        $inputFilter = $this->getInputFilter();
        $inputFilter->add(['name' => 'folder', 'required' => true]);
        $inputFilter->add(['name' => 'recursive', 'required' => false]);
        $inputFilter->add(['name' => 'item_set', 'required' => false]);
        $inputFilter->add(['name' => 'comment', 'required' => false]);
    }
}
