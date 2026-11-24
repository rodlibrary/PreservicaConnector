<?php
namespace PreservicaConnector\Form;

use Laminas\Form\Element;
use Laminas\Form\Form;

class ConfigForm extends Form
{
    public function init()
    {
        if ($this->has('preservicaconnector_host')) {
            return;
        }
        $this->add([
            'name' => 'preservicaconnector_host',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Preservica host', // @translate
                'info' => 'The address of your Preservica system, for example us.preservica.com or eu.preservica.com.', // @translate
            ],
            'attributes' => [
                'id' => 'preservicaconnector_host',
                'required' => true,
                'placeholder' => 'us.preservica.com',
            ],
        ]);
        $this->add([
            'name' => 'preservicaconnector_username',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Username', // @translate
                'info' => 'A Preservica service account, usually an email address. Use a dedicated read-only account without two-factor authentication. Omeka can import whatever this account can read.', // @translate
            ],
            'attributes' => [
                'id' => 'preservicaconnector_username',
                'required' => true,
                'autocomplete' => 'off',
            ],
        ]);
        $this->add([
            'name' => 'preservicaconnector_password',
            'type' => Element\Password::class,
            'options' => [
                'label' => 'Password', // @translate
            ],
            'attributes' => [
                'id' => 'preservicaconnector_password',
                'autocomplete' => 'new-password',
            ],
        ]);
        $this->add([
            'name' => 'preservicaconnector_tenant',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Tenant', // @translate
                'info' => 'Optional. Only needed if the account belongs to more than one Preservica tenant.', // @translate
            ],
            'attributes' => [
                'id' => 'preservicaconnector_tenant',
            ],
        ]);
        $this->add([
            'name' => 'preservicaconnector_fallback_preservation',
            'type' => Element\Checkbox::class,
            'options' => [
                'label' => 'Use preservation files when there is no access copy', // @translate
                'info' => 'Access copies are always preferred. If unchecked, assets without an access representation are imported as metadata only.', // @translate
            ],
            'attributes' => [
                'id' => 'preservicaconnector_fallback_preservation',
            ],
        ]);
        $this->add([
            'name' => 'preservicaconnector_store_reference',
            'type' => Element\Checkbox::class,
            'options' => [
                'label' => 'Record the Preservica reference', // @translate
                'info' => 'Add the asset reference (UUID) to each imported item as a Dublin Core Identifier.', // @translate
            ],
            'attributes' => [
                'id' => 'preservicaconnector_store_reference',
            ],
        ]);

        $this->add([
            'name' => 'preservicaconnector_media_mode',
            'type' => Element\Radio::class,
            'options' => [
                'label' => 'Media for imported items', // @translate
                'info' => 'Linking keeps one copy of each file on the discovery platform: items get the asset\'s IIIF manifest from the platform, found by its shared identifier, and no files are copied into Omeka. Assets without a shared identifier are then not imported.', // @translate
                'value_options' => [
                    'copy' => 'Copy files from Preservica into Omeka', // @translate
                    'iiif' => 'Link to IIIF manifests on the discovery platform', // @translate
                ],
            ],
            'attributes' => [
                'id' => 'preservicaconnector_media_mode',
                'value' => 'copy',
            ],
        ]);
        $this->add([
            'name' => 'preservicaconnector_shared_id_type',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Shared identifier type', // @translate
                'info' => 'The type of the Preservica external identifier that holds the shared identifier, for example the one used for ArchivesSpace component IDs. Needed for linking.', // @translate
            ],
            'attributes' => [
                'id' => 'preservicaconnector_shared_id_type',
            ],
        ]);
        $this->add([
            'name' => 'preservicaconnector_manifest_url',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'IIIF manifest URL pattern', // @translate
                'info' => 'The address of an item\'s IIIF manifest on the discovery platform, with {id} where the shared identifier goes. Needed for linking.', // @translate
            ],
            'attributes' => [
                'id' => 'preservicaconnector_manifest_url',
                'placeholder' => 'https://discovery.example.edu/iiif/{id}/manifest',
            ],
        ]);
        $this->add([
            'name' => 'preservicaconnector_record_url',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Discovery record URL pattern', // @translate
                'info' => 'Optional. The address of an item\'s record in the discovery site, with {id} where the shared identifier goes. Linked items get it as a Relation.', // @translate
            ],
            'attributes' => [
                'id' => 'preservicaconnector_record_url',
                'placeholder' => 'https://discovery.example.edu/catalog/{id}',
            ],
        ]);

        $inputFilter = $this->getInputFilter();
        $inputFilter->add(['name' => 'preservicaconnector_password', 'required' => false]);
        $inputFilter->add(['name' => 'preservicaconnector_tenant', 'required' => false]);
        $inputFilter->add(['name' => 'preservicaconnector_fallback_preservation', 'required' => false]);
        $inputFilter->add(['name' => 'preservicaconnector_store_reference', 'required' => false]);
        $inputFilter->add(['name' => 'preservicaconnector_media_mode', 'required' => false]);
        $inputFilter->add(['name' => 'preservicaconnector_shared_id_type', 'required' => false]);
        $inputFilter->add(['name' => 'preservicaconnector_manifest_url', 'required' => false]);
        $inputFilter->add(['name' => 'preservicaconnector_record_url', 'required' => false]);
    }
}
