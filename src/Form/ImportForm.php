<?php
namespace PreservicaConnector\Form;

use Laminas\Form\Element;
use Laminas\Form\Form;
use Omeka\Form\Element\ItemSetSelect;

/**
 * Options for an import. The selected asset references are posted alongside
 * as refs[] and validated by the controller.
 */
class ImportForm extends Form
{
    public function init()
    {
        $this->add([
            'name' => 'item_set',
            'type' => ItemSetSelect::class,
            'options' => [
                'label' => 'Add to item set', // @translate
                'info' => 'Optional. Imported items are added to this item set.', // @translate
                'empty_option' => 'No item set', // @translate
            ],
            'attributes' => [
                'id' => 'preservica-item-set',
                'class' => 'chosen-select',
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
                'id' => 'preservica-comment',
            ],
        ]);
        $this->add([
            'name' => 'query',
            'type' => Element\Hidden::class,
        ]);

        $inputFilter = $this->getInputFilter();
        $inputFilter->add(['name' => 'item_set', 'required' => false]);
        $inputFilter->add(['name' => 'comment', 'required' => false]);
        $inputFilter->add(['name' => 'query', 'required' => false]);
    }
}
